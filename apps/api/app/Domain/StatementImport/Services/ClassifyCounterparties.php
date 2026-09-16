<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Domain\StatementImport\DataTransferObjects\AiAnswer;
use App\Domain\StatementImport\Enums\RowProposalSource;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Domain\Wallet\Enums\MoneyDirection;
use App\Domain\Wallet\Services\TagDirectory;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

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
        $size = max(1, $this->config->integer('statement_import.ai.classification_batch'));
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
                    'description' => $group['description'],
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
            $this->config->string('statement_import.prompts.classification_system'),
            $this->config->string('statement_import.prompts.classification_instruction'),
            $sent,
            self::schema(),
            $this->config->string('statement_import.ai.classification_model'),
            $this->config->integer('statement_import.ai.classification_max_tokens'),
            $this->config->integer('statement_import.ai.timeout_seconds'),
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
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The rows gathered by counterparty and direction.
     *
     * Direction is part of the identity: money *to* a friend is a loan and money *from* them is its
     * repayment, and one answer for both would be wrong half the time.
     *
     * @param  list<StatementImportRow>  $rows
     * @return list<array{id: string, direction: string, name: string, description: string, count: int, row_ids: list<string>}>
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
                    'description' => MaskedStatementText::mask($description),
                    'count' => 0,
                    'row_ids' => [],
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['row_ids'][] = $row->id;
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
     * @param  list<array{id: string, direction: string, name: string, description: string, count: int, row_ids: list<string>}>  $groups
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
            $rows = StatementImportRow::withoutGlobalScope(TaxSubjectScope::class)
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
