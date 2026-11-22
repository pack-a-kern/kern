<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Services\ResolveTaskModel;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\StatementImport\DataTransferObjects\AiAnswer;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;
use Kern\TaxRomania\Wallet\Services\CounterpartyTransactions;
use Kern\TaxRomania\Wallet\Services\TagDirectory;
use Kern\TaxRomania\Wallet\Services\TaxCategoryCatalogue;

/**
 * Asks a model about the counterparties nobody has answered, and applies what it says.
 *
 * ## Why this is not part of an import
 *
 * It was, and being there was the problem. A person who has just uploaded a statement waits while a
 * model reads a hundred names — and the names are not a property of that file. A shop paid every
 * week is answered once and reused every month afterwards, so the work belongs on a schedule and the
 * import belongs finished.
 *
 * ## What it asks about
 *
 * Rows of {@see CounterpartyDirectory} with no category and no record of having been asked. Asked
 * once: a model that looked at a name and had nothing to say should not be asked again in ten
 * minutes for ever, so `analysed_at` is stamped whether or not an answer came back, and a name it
 * declined waits for a person instead.
 *
 * ## What it does with the answer
 *
 * Writes it onto the counterparty, marked `AI`, and onto every transaction under that name that
 * nobody has classified. Applying rather than proposing is a deliberate choice and a reversible one:
 * the rows say `AI` and a person changes any of them, where a queue of proposals nobody opens leaves
 * a ledger permanently half-answered.
 *
 * A person's own answer is never touched. Not the counterparty's — {@see CounterpartyDirectory::remember()}
 * refuses that — and not a transaction's, because only rows with no split at all are given one.
 *
 * ## Why it lives beside the import's classifier rather than in Wallet
 *
 * It is the same question, to the same provider, with the same prompt and the same masking — and
 * the module boundary runs one way: StatementImport may reach into Wallet, Wallet may not reach
 * back. A Wallet service calling this provider would be a violation rather than an oversight.
 *
 * ## What leaves the installation
 *
 * The same thing the import used to send and nothing more: masked counterparty names, a masked
 * sample line, a direction and a count. No amounts, no dates, no account. See
 * {@see MaskedStatementText}.
 */
final readonly class ClassifyKnownCounterparties
{
    public function __construct(
        private StatementAiClient $ai,
        private TaxCategoryCatalogue $categories,
        private TagDirectory $tags,
        private CounterpartyDirectory $directory,
        private CounterpartyTransactions $rows,
        private CounterpartyPrompt $prompt,
        private ConfigRepository $config,
        private ResolveTaskModel $resolveTaskModel,
    ) {
    }

    /**
     * The batches of this taxpayer's unanswered counterparties, as ids.
     *
     * Ids rather than rows, because a queue payload is a message and these are names: the rows are
     * read back where the work happens. Cut between counterparties and never through one, so a
     * counterparty is asked about once.
     *
     * @return list<list<string>>
     */
    public function batches(string $subjectId): array
    {
        /** @var int<1, max> $size */
        $size = max(1, $this->config->integer('ai.statements.classification.batch'));

        /** @var list<string> $ids */
        $ids = Counterparty::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tax_subject_id', $subjectId)
            ->whereNull('category_code')
            ->whereNull('analysed_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_chunk($ids, $size);
    }

    /**
     * One batch: one call to the model, and what it said applied.
     *
     * Sized so a queue job can be given a timeout that covers exactly this. The whole taxpayer in
     * one job would be several calls of several minutes each, and a worker timeout in the middle of
     * the third would throw away the first two along with any record of why.
     *
     * @param  list<string>  $counterpartyIds
     * @return array{asked: int, answered: int, transactions: int, failure: string|null}
     */
    public function classifyBatch(string $subjectId, array $counterpartyIds): array
    {
        if (! $this->ai->isAvailable($subjectId)) {
            return ['asked' => 0, 'answered' => 0, 'transactions' => 0, 'failure' => AiAnswer::UNAVAILABLE];
        }

        /** @var list<Counterparty> $counterparties */
        $counterparties = Counterparty::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tax_subject_id', $subjectId)
            ->whereIn('id', $counterpartyIds)
            // Answered while this waited in the queue: theirs stands, and asking again would spend a
            // call to be told something the table already knows.
            ->whereNull('category_code')
            ->whereNull('analysed_at')
            ->get()
            ->all();

        if ($counterparties === []) {
            return ['asked' => 0, 'answered' => 0, 'transactions' => 0, 'failure' => null];
        }

        $outcome = $this->ask($subjectId, $counterparties);

        return ['asked' => count($counterparties)] + $outcome;
    }

    /**
     * @param  list<Counterparty>  $counterparties
     * @return array{answered: int, transactions: int, failure: string|null}
     */
    private function ask(string $subjectId, array $counterparties): array
    {
        $allowed = $this->categoriesByDirection();
        $byId = [];
        $payload = [];

        foreach ($counterparties as $position => $counterparty) {
            $id = 'c' . (string) ($position + 1);
            $byId[$id] = $counterparty;

            $payload[] = [
                'id' => $id,
                'direction' => $counterparty->direction === MoneyDirection::OUT ? 'out' : 'in',
                'name' => MaskedStatementText::mask((string) $counterparty->counterparty_ciphertext),
                'descriptions' => $this->descriptionsFor($subjectId, $counterparty),
                'rows' => $this->rows->countFor($subjectId, $counterparty),
            ];
        }

        $answer = $this->ai->ask(
            $subjectId,
            $this->prompt->system($subjectId),
            $this->prompt->instruction(),
            [
                'categories_in' => $allowed['in'],
                'categories_out' => $allowed['out'],
                'existing_tags' => array_values($this->tags->all()),
                'counterparties' => $payload,
            ],
            ClassifyCounterparties::schema(),
            ($this->resolveTaskModel)(SettingKey::AI_STATEMENT_CLASSIFICATION_MODEL),
            $this->config->integer('ai.statements.classification.max_tokens'),
            $this->config->integer('ai.statements.timeout_seconds'),
        );

        // Stamped whether or not it answered, and before the answer is read: a name a model declined
        // is a name for a person, not a name to ask about again in ten minutes.
        $this->stampAnalysed($counterparties);

        if (! $answer->succeeded() || $answer->data === null) {
            return ['answered' => 0, 'transactions' => 0, 'failure' => $answer->failure ?? AiAnswer::UNREADABLE];
        }

        return $this->apply($subjectId, $answer->data, $byId) + ['failure' => null];
    }

    /**
     * Mark these as looked at, answered or not.
     *
     * Before the reply is read, deliberately. A name a model declined is a name for a person to
     * answer, and without this it would be asked about again in ten minutes, and again after that,
     * for ever — a cost that grows with every unrecognisable shop in the ledger.
     *
     * A person clearing the category later does not clear this: they have seen it, which is more
     * than a model looking again would add.
     *
     * @param  list<Counterparty>  $counterparties
     */
    private function stampAnalysed(array $counterparties): void
    {
        $ids = array_map(static fn (Counterparty $row): string => $row->id, $counterparties);

        if ($ids === []) {
            return;
        }

        Counterparty::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $ids)
            ->update(['analysed_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, Counterparty>  $byId
     * @return array{answered: int, transactions: int}
     */
    private function apply(string $subjectId, array $answer, array $byId): array
    {
        $answered = 0;
        $transactions = 0;
        $maxTags = max(1, $this->config->integer('statement_import.limits.max_tags_per_row'));

        foreach (is_array($answer['results'] ?? null) ? $answer['results'] : [] as $result) {
            if (! is_array($result)) {
                continue;
            }

            $counterparty = $byId[is_string($result['id'] ?? null) ? $result['id'] : ''] ?? null;

            if ($counterparty === null) {
                continue;
            }

            $category = is_string($result['category'] ?? null)
                && in_array($result['category'], $this->categories->codes(), true)
                && $this->fitsDirection($result['category'], $counterparty->direction)
                    ? $result['category']
                    : null;

            $tagNames = [];

            foreach (is_array($result['tags'] ?? null) ? $result['tags'] : [] as $tag) {
                $name = is_string($tag) ? TagDirectory::normalise($tag) : '';

                if ($name !== '' && ! in_array($name, $tagNames, true) && count($tagNames) < $maxTags) {
                    $tagNames[] = $name;
                }
            }

            if ($category === null && $tagNames === []) {
                continue;
            }

            $answered++;
            $tagIds = $tagNames === [] ? [] : $this->tags->idsFor($tagNames);

            $this->directory->remember(
                $counterparty->counterparty_blind_index,
                (string) $counterparty->counterparty_ciphertext,
                $counterparty->direction,
                $category,
                null,
                $tagIds,
                ProposalSource::AI,
            );

            $transactions += $this->rows->applyAnswer($subjectId, $counterparty, $category, $tagIds);
        }

        return ['answered' => $answered, 'transactions' => $transactions];
    }

    /**
     * The distinct statement lines seen under this counterparty.
     *
     * @return list<string>
     */
    private function descriptionsFor(string $subjectId, Counterparty $counterparty): array
    {
        $max = max(1, $this->config->integer('ai.statements.classification.max_descriptions'));

        // Masked here rather than by the reader: what may reach a model is this module's rule.
        return array_map(
            static fn (string $line): string => MaskedStatementText::mask($line),
            $this->rows->descriptionsFor($subjectId, $counterparty, $max),
        );
    }

    /** @return array{in: list<string>, out: list<string>} */
    private function categoriesByDirection(): array
    {
        $in = [];
        $out = [];

        foreach ($this->categories->toArray() as $category) {
            if ($category['direction'] !== 'out') {
                $in[] = $category['code'];
            }

            if ($category['direction'] !== 'in') {
                $out[] = $category['code'];
            }
        }

        return ['in' => $in, 'out' => $out];
    }

    private function fitsDirection(string $category, MoneyDirection $direction): bool
    {
        return $this->categories->fitsDirection($category, $direction === MoneyDirection::OUT ? -1 : 1);
    }

}
