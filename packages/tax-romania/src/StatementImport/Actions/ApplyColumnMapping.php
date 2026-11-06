<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Actions;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Documents\Models\Document;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementForm;
use Kern\TaxRomania\StatementImport\Enums\MappingSource;
use Kern\TaxRomania\StatementImport\Enums\RowProposalSource;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Events\StatementImportChanged;
use Kern\TaxRomania\StatementImport\Exceptions\CurrencyRequiredException;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\StatementImport\Gateways\StatementParseGateway;
use Kern\TaxRomania\StatementImport\Jobs\ProposeRowCategories;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\StatementImport\Services\RowFingerprint;
use Kern\TaxRomania\StatementImport\Services\StatementFileContents;
use Kern\TaxRomania\StatementImport\Services\StatementRowBuilder;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;

/**
 * Turns the file into rows a person can go through, using the mapping they confirmed.
 *
 * ## Four things happen here, in this order
 *
 * 1. **The file is read again.** The mapping screen worked from a sample; the rows come from the
 *    whole file, read with the mapping now agreed. Re-reading rather than storing the grid keeps one
 *    copy of the statement — the document — instead of two.
 * 2. **Every row is fingerprinted**, so importing the same statement twice adds nothing.
 * 3. **Rows already in the ledger are marked** and arrive unticked: a person sees what would have
 *    been a duplicate rather than discovering it afterwards.
 * 4. **Remembered answers are applied.** A counterparty answered before gets that answer proposed,
 *    with `RULE` beside it. Nothing is sent anywhere: this step is why most rows of a second
 *    statement need no model at all.
 */
final readonly class ApplyColumnMapping
{
    /** Staged rows per insert: a few hundred encrypted rows per statement, well inside any driver's limit. */
    private const int INSERT_BATCH = 200;

    public function __construct(
        private StatementParseGateway $parser,
        private StatementFileContents $files,
        private StatementRowBuilder $builder,
        private RowFingerprint $fingerprints,
        private CounterpartyDirectory $rules,
        private ConfigRepository $config,
    ) {
    }

    public function __invoke(
        StatementImport $import,
        StatementForm $form,
        MappingSource $source,
        ?string $actorId = null,
    ): StatementImport {
        if (! $import->status->isOpen()) {
            throw ImportNotOpenException::forImport($import->id);
        }

        $parsed = $this->parser->parse($this->contents($import), $import->sheet);

        $account = FinancialAccount::query()->find($import->account_id);

        /*
         * Asked before a single row is staged.
         *
         * Every row's currency comes from the file or, failing that, from the account — and a row
         * with neither can never become a transaction, because the ledger's column is not nullable.
         * Finding that out after staging leaves a person looking at a screen of rows that cannot be
         * imported and an Import button that answers "0".
         */
        if ($form->currency === null && $account?->currency_code === null) {
            throw new CurrencyRequiredException();
        }

        $built = $this->builder->build(
            $parsed->rows,
            $form,
            $this->config->float('statement_import.limits.parse_threshold'),
            $account?->currency_code,
            // What each column is called, so a cell can be kept under a name rather than an index.
            //
            // From the preview's own `columns`, which the parser worked out — not from the file's
            // header line. `header_row` counts lines in the whole file, and the rows reaching here
            // begin at the first transaction, so indexing them by it lands on a detail line: every
            // cell was briefly stored under a header reading `Tranzactie la:MEGAIMAGE 0290 …`.
            self::headers($import),
        );
        $rows = $built->rows;

        $fingerprints = $this->fingerprints->forRows(
            array_map(
                static fn (array $row): array => [
                    'booked_at' => $row['booked_at'],
                    'amount_minor' => $row['amount_minor'],
                    'currency_code' => $row['currency_code'],
                    'text' => $row['text'] . ' ' . $row['counterparty'],
                    'reference' => $row['reference'],
                ],
                $rows,
            ),
            $import->account_id,
            $import->bank_id,
        );

        $existing = $this->existingByFingerprint($import->account_id, array_values(array_filter($fingerprints)));
        // Every counterparty in the file, indexed in one request and used twice: to look up the
        // remembered answers in one query, and for each row below.
        $counterpartyIndexes = $this->rules->indexesFor(array_column($rows, 'counterparty'));
        $ruleFor = $this->rules->forIndexes(array_values(array_unique(array_filter($counterpartyIndexes, is_string(...)))));

        DB::transaction(function () use ($import, $form, $source, $rows, $built, $fingerprints, $existing, $counterpartyIndexes, $ruleFor, $parsed): void {
            // Replace, never append: a person who fixes one column and applies the mapping again is
            // correcting this import, not adding a second copy of the statement to it.
            StatementImportRow::query()->where('import_id', $import->id)->delete();

            $duplicates = 0;
            $batch = [];
            $now = now('UTC');

            foreach ($rows as $index => $row) {
                $fingerprint = $fingerprints[$index] ?? null;
                $duplicateId = $fingerprint === null ? null : ($existing[$fingerprint] ?? null);
                $counterpartyIndex = $counterpartyIndexes[$index];
                $rule = $counterpartyIndex === null || $row['amount_minor'] === null
                    ? null
                    : ($ruleFor[CounterpartyDirectory::key($counterpartyIndex, MoneyDirection::ofAmount($row['amount_minor']))] ?? null);

                if ($duplicateId !== null) {
                    $duplicates++;
                }

                $staged = new StatementImportRow([
                    'tax_subject_id' => $import->tax_subject_id,
                    'import_id' => $import->id,
                    'row_number' => $row['row_number'],
                    'booked_at' => $row['booked_at'],
                    'amount_minor' => $row['amount_minor'],
                    'currency_code' => $row['currency_code'],
                    'description_ciphertext' => $row['text'],
                    // Every field the file named for this transaction — see the column's migration.
                    'fields_ciphertext' => $row['fields'] === []
                        ? null
                        : json_encode($row['fields'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'counterparty_ciphertext' => $row['counterparty'] === '' ? null : $row['counterparty'],
                    'counterparty_blind_index' => $counterpartyIndex,
                    'reference_ciphertext' => $row['reference'] === '' ? null : $row['reference'],
                    'fingerprint' => $fingerprint,
                    'duplicate_transaction_id' => $duplicateId,
                    'category_code' => $rule?->category_code,
                    'country_code' => $rule?->country_code,
                    'tags_ciphertext' => $rule === null ? null : json_encode(
                        $rule->tags->map(static fn ($tag): string => (string) $tag->name_ciphertext)->all(),
                        JSON_THROW_ON_ERROR,
                    ),
                    'proposal_source' => $rule === null ? null : RowProposalSource::RULE,
                    /*
                     * A row already in the ledger, or one this could not read, starts unticked: the
                     * default should never be to import something a person has not looked at.
                     *
                     * Currency counts as unread. A transaction stores one and the column is not
                     * nullable, so a row without it cannot be saved at all — better shown unticked
                     * here, where the form above can still answer it for the whole file, than as a
                     * job that fails after a person has pressed Import.
                     */
                    'included' => $duplicateId === null
                        && $row['booked_at'] !== null
                        && $row['amount_minor'] !== null
                        && $row['currency_code'] !== null,
                ]);

                // Built through the model, so every field is encrypted by its cast exactly as a save
                // would; written in batches, because one insert per row made a long statement slow.
                // A batch is flushed as it fills, so what is held is one batch, never the file twice.
                $batch[] = $staged->setCreatedAt($now)->setUpdatedAt($now)->getAttributes();

                if (count($batch) === self::INSERT_BATCH) {
                    StatementImportRow::query()->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                StatementImportRow::query()->insert($batch);
            }

            $import->column_mapping = $form->toArray();
            $import->mapping_source = $source;
            $import->format = $parsed->format;
            $import->row_count = count($rows);
            $import->lines_read = $built->linesRead;
            $import->lines_folded = $built->linesFolded;
            $import->lines_skipped = $built->linesSkipped;
            $import->duplicate_count = $duplicates;
            $import->excluded_count = $duplicates;
            $import->status = StatementImportStatus::REVIEW;
            $import->failure_reason = null;
            $import->save();
        });

        /*
         * What the remembered answers could not cover goes to the model, in the background: a
         * statement's unanswered counterparties are usually a handful, and the review screen is
         * usable while they arrive. The job leaves every row it cannot answer alone.
         */
        /*
         * Switched off: the round is recorded as over, here, rather than queued and discovered.
         *
         * `queueClassification()` writes a round with no outcome yet, which is what the review screen
         * waits behind — so dispatching a job that would read this same setting and immediately
         * record `TURNED_OFF` meant a person watched "waiting for suggestions" until a worker picked
         * it up, to be told something this request already knew.
         *
         * The outcome recorded is the same one the job would have written, so nothing downstream can
         * tell the difference; only the waiting is gone.
         */
        if (! $this->config->boolean('ai.statements.classification.enabled')) {
            $import->startClassification((string) Str::uuid(), 0, 0, ProposeRowCategories::TURNED_OFF);
        } else {
            // The actor travels with it: the job reads this person's tags and remembered answers, and
            // a queue worker has no one bound until it is told who.
            $import->queueClassification();

            ProposeRowCategories::dispatch($import->id, $actorId);
        }

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);

        return $import;
    }

    /** The statement's own bytes, from the document it was stored as. */
    /** The same bytes the columns were proposed on — the converted CSV, for a converted PDF. */
    private function contents(StatementImport $import): string
    {
        $document = $import->document_id === null ? null : Document::query()->with('parts')->find($import->document_id);
        $contents = $document === null ? null : $this->files->readable($document);

        return $contents ?? throw ImportNotOpenException::forImport($import->id);
    }

    /**
     * Which of these fingerprints the ledger already holds, in one query.
     *
     * @param  list<string>  $fingerprints
     * @return array<string, string> fingerprint => transaction id
     */
    private function existingByFingerprint(string $accountId, array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $found = [];

        foreach (
            DB::table('financial_transactions')
                ->where('account_id', $accountId)
                ->whereIn('external_id_blind_index', $fingerprints)
                ->get(['id', 'external_id_blind_index']) as $row
        ) {
            $found[(string) $row->external_id_blind_index] = (string) $row->id;
        }

        return $found;
    }

    /**
     * Each column's name, by its index.
     *
     * A statement leaves gaps: the spacer columns a spreadsheet puts between the ones that mean
     * something have no header, and a cell under one has nothing to be recorded as.
     *
     * @return list<string>
     */
    private static function headers(StatementImport $import): array
    {
        $headers = [];

        foreach ($import->preview()['columns'] as $column) {
            $index = $column['index'] ?? null;
            $header = $column['header'] ?? null;

            if (is_int($index) && is_string($header)) {
                $headers[$index] = trim($header);
            }
        }

        if ($headers === []) {
            return [];
        }

        // Dense and in order, so a position with no column of its own reads as unnamed rather than
        // taking the next one's name.
        return array_map(
            static fn (int $index): string => $headers[$index] ?? '',
            range(0, max(array_keys($headers))),
        );
    }
}
