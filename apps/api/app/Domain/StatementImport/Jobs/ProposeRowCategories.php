<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Jobs;

use App\Domain\StatementImport\Enums\RowProposalSource;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\StatementImport\Services\MaskedStatementText;
use App\Domain\StatementImport\Services\StatementAiClient;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Enums\MoneyDirection;
use App\Domain\Wallet\Services\TagDirectory;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Proposes a category and tags for the counterparties nobody has answered before.
 *
 * ## Unique counterparties, not rows
 *
 * A statement of four hundred rows is perhaps forty different names, and most of those were answered
 * in an earlier month and already carry a rule. What is left goes to the model once each, in
 * batches — so the cost of a statement is a handful of small calls rather than four hundred, and the
 * second statement from the same account is usually none at all.
 *
 * ## What leaves this application
 *
 * The masked counterparty name, a masked sample description, the direction, and how many rows share
 * it ({@see MaskedStatementText}). No amounts, no dates, no IBANs, no account numbers, and never a
 * CNP. The category codes it may answer with are sent too, so the reply is a choice from a list
 * rather than free text.
 *
 * ## Nothing here decides anything
 *
 * Every proposal lands on a staged row marked `AI`, which a person then goes through. An answer that
 * does not fit the direction of the money, or is not a code this application knows, is dropped
 * rather than shown.
 */
final class ProposeRowCategories implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $importId,
        private readonly ?string $actorId = null,
    ) {
    }

    public function handle(
        StatementAiClient $ai,
        TaxCategoryCatalogue $categories,
        TagDirectory $tags,
        TenantContext $tenant,
        ConfigRepository $config,
    ): void {
        $import = StatementImport::withoutGlobalScope(TaxSubjectScope::class)->find($this->importId);

        // Only while the rows are still being decided: an import already written is not a place to
        // put proposals, and one that failed has no rows.
        if ($import === null || $import->status !== StatementImportStatus::REVIEW) {
            return;
        }

        /*
         * A tenant, or no proposals.
         *
         * Tags and remembered counterparty answers are subject-scoped models, and a queue worker
         * acts for nobody until someone is bound — reading them without that is what left every row
         * of a 1 700-row statement unproposed while the job died with `NoTenantContextException`.
         *
         * No actor means no proposals rather than a failed import: the rows are already staged and
         * answerable by hand. Re-checked rather than assumed, because minutes pass between a person
         * confirming the form and this running, and access revoked in between must stop the read.
         */
        if ($this->actorId === null) {
            return;
        }

        $tenant->authorizeAndActivate($this->actorId, $import->tax_subject_id);

        if (! $ai->isAvailable($import->tax_subject_id)) {
            return;
        }

        /** @var list<StatementImportRow> $rows */
        $rows = StatementImportRow::withoutGlobalScope(TaxSubjectScope::class)
            ->where('import_id', $import->id)
            // Rows a rule already answered are left alone: a person's own earlier decision outranks
            // anything a model would say now.
            ->whereNull('category_code')
            ->whereNotNull('amount_minor')
            ->get()
            ->all();

        if ($rows === []) {
            return;
        }

        $groups = $this->groupByCounterparty($rows);

        if ($groups === []) {
            return;
        }

        $existingTags = array_values($tags->all());
        $byDirection = $this->categoriesByDirection($categories);
        $batch = max(1, $config->integer('statement_import.limits.classification_batch'));
        $maxTags = max(1, $config->integer('statement_import.limits.max_tags_per_row'));

        foreach (array_chunk($groups, $batch, preserve_keys: true) as $chunk) {
            $answer = $ai->ask(
                $import->tax_subject_id,
                $config->string('statement_import.prompts.classification_system'),
                $config->string('statement_import.prompts.classification_instruction'),
                [
                    'categories_in' => $byDirection['in'],
                    'categories_out' => $byDirection['out'],
                    'existing_tags' => $existingTags,
                    'counterparties' => array_values(array_map(
                        static fn (array $group): array => [
                            'id' => $group['id'],
                            'direction' => $group['direction'],
                            'name' => $group['name'],
                            'description' => $group['description'],
                            'rows' => $group['count'],
                        ],
                        $chunk,
                    )),
                ],
                self::schema(),
            );

            if (! $answer->succeeded() || $answer->data === null) {
                // A failed or refused call leaves these rows unproposed. The person answers them,
                // which is what they would have done anyway before this job existed.
                continue;
            }

            $this->applyAnswer($answer->data, $chunk, $categories, $maxTags);
        }
    }

    /**
     * The file's rows gathered by counterparty and direction.
     *
     * Direction is part of the identity: money *to* a friend is a loan and money *from* them is its
     * repayment, and one answer for both would be wrong half the time.
     *
     * @param  list<StatementImportRow>  $rows
     * @return array<string, array{id: string, key: string, direction: string, name: string, description: string, count: int, row_ids: list<string>}>
     */
    private function groupByCounterparty(array $rows): array
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
                    'key' => $key,
                    'direction' => $direction === MoneyDirection::OUT ? 'out' : 'in',
                    'name' => MaskedStatementText::mask($subject),
                    'description' => MaskedStatementText::mask($description),
                    'count' => 0,
                    'row_ids' => [],
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['row_ids'][] = $row->id;
        }

        return $groups;
    }

    /**
     * Which codes may be given to money coming in, and which to money going out.
     *
     * @return array{in: list<string>, out: list<string>}
     */
    private function categoriesByDirection(TaxCategoryCatalogue $categories): array
    {
        $in = [];
        $out = [];

        foreach ($categories->toArray() as $category) {
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
     * @param  array<string, array{id: string, key: string, direction: string, name: string, description: string, count: int, row_ids: list<string>}>  $chunk
     */
    private function applyAnswer(array $answer, array $chunk, TaxCategoryCatalogue $categories, int $maxTags): void
    {
        $byId = [];

        foreach ($chunk as $group) {
            $byId[$group['id']] = $group;
        }

        foreach (is_array($answer['results'] ?? null) ? $answer['results'] : [] as $result) {
            if (! is_array($result)) {
                continue;
            }

            $group = $byId[is_string($result['id'] ?? null) ? $result['id'] : ''] ?? null;

            if ($group === null) {
                continue;
            }

            $category = is_string($result['category'] ?? null) && in_array($result['category'], $categories->codes(), true)
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
            $rows = StatementImportRow::withoutGlobalScope(TaxSubjectScope::class)
                ->whereKey($group['row_ids'])
                ->whereNull('category_code')
                ->get()
                ->all();

            foreach ($rows as $row) {
                // Checked per row rather than per group: the direction is the row's own, and a
                // category that cannot have it is dropped exactly as the save would drop it.
                if ($category !== null && $categories->fitsDirection($category, (int) $row->amount_minor)) {
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
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
