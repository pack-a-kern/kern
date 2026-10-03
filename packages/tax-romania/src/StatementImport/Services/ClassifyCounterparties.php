<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\StatementImport\DataTransferObjects\AiAnswer;
use Kern\TaxRomania\StatementImport\Enums\RowProposalSource;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Services\TagDirectory;
use Kern\TaxRomania\Wallet\Services\TaxCategoryCatalogue;

/**
 * Asks what each counterparty is — a tax category and a few tags — and writes the answers onto rows.
 *
 * ## Unique counterparties, not rows
 *
 * A statement of 1 700 rows is a few hundred different names paid or paying, and one answer per name
 * and direction is enough. So rows are grouped first, and only the groups travel: a shop paid sixty
 * times is asked about once.
 *
 * ## What leaves this application
 *
 * For each group: its direction, the counterparty name and a sample description with long digit runs
 * and IBANs replaced ({@see MaskedStatementText}), and how many rows share it. Names themselves are
 * sent — a shop's name is the question — and so is a person's name when that is who paid. No amounts
 * and no dates. The category codes allowed for each direction go too, so the answer is a choice from
 * a list rather than free text.
 *
 * ## Nothing here decides anything
 *
 * Every answer lands on a staged row marked `AI`, which a person then goes through. An answer that
 * does not fit the direction of the money, or is not a code this application knows, is dropped
 * rather than shown.
 */
final readonly class ClassifyCounterparties
{
    public function __construct(
        private StatementAiClient $ai,
        private TaxCategoryCatalogue $categories,
        private TagDirectory $tags,
        private ConfigRepository $config,
        private CounterpartyPrompt $prompt,
    ) {
    }

    /**
     * The rows split into the batches that will each be one call.
     *
     * Split by counterparty, never through one: all sixty rows for a shop travel in the same batch,
     * so one answer covers them and no shop is asked about twice.
     *
     * @param  list<StatementImportRow>  $rows
     * @return list<list<string>> row ids, one list per call
     */
    public function batches(array $rows): array
    {
        $size = max(1, $this->config->integer('ai.statements.classification.batch'));
        $batches = [];

        foreach (array_chunk($this->groups($rows), $size) as $chunk) {
            $ids = [];

            foreach ($chunk as $group) {
                array_push($ids, ...$group['row_ids']);
            }

            $batches[] = $ids;
        }

        return $batches;
    }

    /**
     * How many distinct counterparty-and-direction pairs these rows are — what the calls ask about.
     *
     * @param  list<StatementImportRow>  $rows
     */
    public function counterpartyCount(array $rows): int
    {
        return count($this->groups($rows));
    }

    /**
     * One call: what was sent, what came back, and why nothing did when nothing did.
     *
     * The sent payload and the reply are returned rather than logged, so the import can keep them for
     * a person checking why a counterparty got the category it got.
     *
     * @param  list<StatementImportRow>  $rows
     * @return array{sent: array<string, mixed>, reply: array<string, mixed>|null, failure: string|null}
     */
    public function classify(string $subjectId, array $rows): array
    {
        $groups = $this->groups($rows);
        $allowed = $this->categoriesByDirection();

        $sent = [
            'categories_in' => $allowed['in'],
            'categories_out' => $allowed['out'],
            'existing_tags' => array_values($this->tags->all()),
            'counterparties' => array_map(
                static fn (array $group): array => [
                    'id' => $group['id'],
                    'direction' => $group['direction'],
                    'name' => $group['name'],
                    'descriptions' => $group['descriptions'],
                    'rows' => $group['count'],
                ],
                $groups,
            ),
        ];

        if ($groups === []) {
            return ['sent' => $sent, 'reply' => null, 'failure' => null];
        }

        $answer = $this->ai->ask(
            $subjectId,
            $this->prompt->system($subjectId),
            $this->prompt->instruction(),
            $sent,
            self::schema(),
            $this->config->string('ai.statements.classification.model'),
            $this->config->integer('ai.statements.classification.max_tokens'),
            $this->config->integer('ai.statements.timeout_seconds'),
        );

        if (! $answer->succeeded() || $answer->data === null) {
            return ['sent' => $sent, 'reply' => null, 'failure' => $answer->failure ?? AiAnswer::UNREADABLE];
        }

        $this->apply($answer->data, $groups);

        return ['sent' => $sent, 'reply' => $answer->data, 'failure' => null];
    }

    /**
     * The shape the reply must have: one entry per counterparty it was given.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['results'],
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'category' => ['type' => ['string', 'null']],
                            /*
                             * One tag, in the schema rather than only in the prompt. The prompt tells
                             * the model its output is constrained; that claim should be true of the
                             * thing the application actually depends on.
                             */
                            'tags' => ['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * How many distinct statement lines one counterparty may send.
     *
     * A cap rather than a limit anybody meets often: the average counterparty has one or two lines,
     * and this is for the supermarket that prints thirteen branch names.
     */
    private function maxDescriptions(): int
    {
        return max(1, $this->config->integer('ai.statements.classification.max_descriptions'));
    }

    /**
     * The rows gathered by counterparty and direction.
     *
     * Direction is part of the identity: money *to* a friend is a loan and money *from* them is its
     * repayment, and one answer for both would be wrong half the time.
     *
     * @param  list<StatementImportRow>  $rows
     * @return list<array{id: string, direction: string, name: string, descriptions: list<string>, count: int, row_ids: list<string>}>
     */
    private function groups(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->counterparty_ciphertext ?? ''));
            $description = trim((string) ($row->description_ciphertext ?? ''));
            $subject = $name !== '' ? $name : $description;

            if ($subject === '') {
                continue;
            }

            $direction = MoneyDirection::ofAmount((int) $row->amount_minor);
            $key = mb_strtolower($subject) . '|' . $direction->value;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    // A short opaque id rather than the name: the reply is matched back by it, and a
                    // name echoed in both directions is one more copy of it in the payload.
                    'id' => 'c' . (string) (count($groups) + 1),
                    'direction' => $direction === MoneyDirection::OUT ? 'out' : 'in',
                    'name' => MaskedStatementText::mask($subject),
                    'descriptions' => [],
                    'count' => 0,
                    'row_ids' => [],
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['row_ids'][] = $row->id;

            /*
             * Every distinct line, not one sample.
             *
             * One counterparty is often one merchant said several ways — a supermarket printing its
             * branch, a petrol station its number — and those add nothing. The case that matters is
             * the other one: under a person's own name sit a matured deposit, an account closure, a
             * bank transfer and a personal exchange, four different events behind one name. Sending
             * one sample meant choosing which of the four spoke for the other twenty-five rows.
             *
             * It costs little. Across a real statement the average counterparty has 1.2 distinct
             * lines; the cap is for the handful with a dozen branches.
             */
            $line = MaskedStatementText::mask($description);

            if ($line !== ''
                && count($groups[$key]['descriptions']) < $this->maxDescriptions()
                && ! in_array($line, $groups[$key]['descriptions'], true)) {
                $groups[$key]['descriptions'][] = $line;
            }
        }

        return array_values($groups);
    }

    /**
     * Which codes may be given to money coming in, and which to money going out.
     *
     * @return array{in: list<string>, out: list<string>}
     */
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

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<array{id: string, direction: string, name: string, descriptions: list<string>, count: int, row_ids: list<string>}>  $groups
     */
    private function apply(array $answer, array $groups): void
    {
        $byId = [];

        foreach ($groups as $group) {
            $byId[$group['id']] = $group;
        }

        $maxTags = max(1, $this->config->integer('statement_import.limits.max_tags_per_row'));

        foreach (is_array($answer['results'] ?? null) ? $answer['results'] : [] as $result) {
            if (! is_array($result)) {
                continue;
            }

            $group = $byId[is_string($result['id'] ?? null) ? $result['id'] : ''] ?? null;

            if ($group === null) {
                continue;
            }

            $category = is_string($result['category'] ?? null) && in_array($result['category'], $this->categories->codes(), true)
                ? $result['category']
                : null;

            /** @var list<string> $tagNames */
            $tagNames = [];

            foreach (is_array($result['tags'] ?? null) ? $result['tags'] : [] as $tag) {
                $normalised = is_string($tag) ? TagDirectory::normalise($tag) : '';

                if ($normalised !== '' && ! in_array($normalised, $tagNames, true) && count($tagNames) < $maxTags) {
                    $tagNames[] = $normalised;
                }
            }

            if ($category === null && $tagNames === []) {
                continue;
            }

            /** @var list<StatementImportRow> $rows */
            $rows = StatementImportRow::withoutGlobalScope(TenantScope::class)
                ->whereKey($group['row_ids'])
                ->whereNull('category_code')
                ->get()
                ->all();

            foreach ($rows as $row) {
                // Checked per row rather than per group: the direction is the row's own, and a
                // category that cannot have it is dropped exactly as the save would drop it.
                if ($category !== null && $this->categories->fitsDirection($category, (int) $row->amount_minor)) {
                    $row->category_code = $category;
                }

                if ($tagNames !== []) {
                    $row->tags_ciphertext = json_encode($tagNames, JSON_THROW_ON_ERROR);
                }

                $row->proposal_source = RowProposalSource::AI;
                $row->save();
            }
        }
    }
}
