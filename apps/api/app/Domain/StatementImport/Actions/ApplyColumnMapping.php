<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\StatementImport\DataTransferObjects\StatementForm;
use App\Domain\StatementImport\Enums\MappingSource;
use App\Domain\StatementImport\Enums\RowProposalSource;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Exceptions\CurrencyRequiredException;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Gateways\StatementParseGateway;
use App\Domain\StatementImport\Jobs\ProposeRowCategories;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\StatementImport\Services\RowFingerprint;
use App\Domain\StatementImport\Services\StatementRowBuilder;
use App\Domain\Wallet\Enums\MoneyDirection;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Services\CounterpartyRules;
use App\Support\ObjectStore\EncryptedObjectStore;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;

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
    public function __construct(
        private StatementParseGateway $parser,
        private EncryptedObjectStore $store,
        private StatementRowBuilder $builder,
        private RowFingerprint $fingerprints,
        private CounterpartyRules $rules,
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
        $ruleFor = $this->rulesFor($rows);

        DB::transaction(function () use ($import, $form, $source, $rows, $built, $fingerprints, $existing, $ruleFor, $parsed): void {
            // Replace, never append: a person who fixes one column and applies the mapping again is
            // correcting this import, not adding a second copy of the statement to it.
            StatementImportRow::query()->where('import_id', $import->id)->delete();

            $duplicates = 0;

            foreach ($rows as $index => $row) {
                $fingerprint = $fingerprints[$index] ?? null;
                $duplicateId = $fingerprint === null ? null : ($existing[$fingerprint] ?? null);
                $counterpartyIndex = $this->rules->indexFor($row['counterparty']);
                $rule = $counterpartyIndex === null || $row['amount_minor'] === null
                    ? null
                    : ($ruleFor[CounterpartyRules::key($counterpartyIndex, MoneyDirection::ofAmount($row['amount_minor']))] ?? null);

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
                $staged->save();
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
        // The actor travels with it: the job reads this person's tags and remembered answers, and a
        // queue worker has no one bound until it is told who.
        ProposeRowCategories::dispatch($import->id, $actorId);

        return $import;
    }

    /** The statement's own bytes, from the document it was stored as. */
    private function contents(StatementImport $import): string
    {
        $document = $import->document_id === null ? null : Document::query()->with('parts')->find($import->document_id);
        $part = $document?->firstPart();

        if ($part === null) {
            throw ImportNotOpenException::forImport($import->id);
        }

        $stream = $this->store->get($part->objectKey());
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw ImportNotOpenException::forImport($import->id);
        }

        return $contents;
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
     * The remembered answers for every counterparty in the file, in one query.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, \App\Domain\Wallet\Models\CounterpartyRule>
     */
    private function rulesFor(array $rows): array
    {
        $indexes = [];

        foreach ($rows as $row) {
            $index = $this->rules->indexFor(is_string($row['counterparty'] ?? null) ? $row['counterparty'] : null);

            if ($index !== null) {
                $indexes[$index] = true;
            }
        }

        return $this->rules->forIndexes(array_keys($indexes));
    }
}
