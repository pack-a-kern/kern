<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Jobs;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Events\StatementImportChanged;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Actions\RecordImportedTransactions;
use App\Domain\Wallet\DataTransferObjects\ImportedTransactionInput;
use App\Domain\Wallet\Enums\MoneyDirection;
use App\Domain\Wallet\Enums\ProposalSource;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Services\CounterpartyRules;
use App\Domain\Wallet\Services\TagDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes a reviewed statement into the ledger, in chunks.
 *
 * ## What it does per chunk
 *
 * Turns staged rows into transactions, with their category, their country and their tags, and marks
 * each staged row with the transaction it became — so an undo knows exactly what to remove, and a
 * failure halfway leaves a coherent half rather than an unknown one.
 *
 * ## What it remembers
 *
 * The answers a person confirmed, as counterparty rules. This is the whole reason the second
 * statement from a bank costs almost nothing: the shop answered in March is answered in April
 * without asking anyone, or anything.
 *
 * ## One audit event
 *
 * The import is the act. Four hundred identical events would bury the rest of a subject's chain, and
 * every transaction carries `import_id` anyway, so the row-level trail is intact.
 */
final class WriteStatementRows implements ShouldQueue
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
        RecordImportedTransactions $record,
        TagDirectory $tags,
        CounterpartyRules $rules,
        AuditChainService $audit,
        TenantContext $tenant,
        ConfigRepository $config,
    ): void {
        $import = StatementImport::withoutGlobalScope(TaxSubjectScope::class)->find($this->importId);

        if ($import === null || $import->status !== StatementImportStatus::IMPORTING) {
            return;
        }

        /*
         * A queue worker acts for nobody, and everything below — tags, rules, the transactions
         * themselves — is written through tenant-scoped models. So the subject is bound the only way
         * this codebase allows: by the person who pressed Import, whose membership is re-checked
         * here against the database.
         *
         * That re-check is the point rather than a formality. Minutes pass between pressing Import
         * and this job running, and access revoked in between must stop the write — there is
         * deliberately no "just set the subject id" shortcut (`TenantContext`).
         */
        if ($this->actorId === null) {
            $import->status = StatementImportStatus::FAILED;
            $import->failure_reason = 'NO_ACTOR';
            $import->save();

            StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);

            return;
        }

        $tenant->authorizeAndActivate($this->actorId, $import->tax_subject_id);

        $chunk = max(1, $config->integer('statement_import.limits.commit_chunk'));
        $imported = 0;

        StatementImportRow::query()
            ->where('import_id', $import->id)
            ->where('included', true)
            ->whereNull('transaction_id')
            ->whereNotNull('booked_at')
            ->whereNotNull('amount_minor')
            // A transaction's currency column is `char(3)` and not nullable, so a row without one
            // would be written as three blank characters rather than refused — a figure in no
            // currency at all, which no report could ever add up. Left behind instead.
            ->whereNotNull('currency_code')
            ->orderBy('row_number')
            ->chunkById($chunk, function ($rows) use ($import, $record, $tags, $rules, &$imported): void {
                $inputs = [];
                $tagIdsByRow = [];

                foreach ($rows as $row) {
                    $bookedAt = $row->booked_at?->toDateString();

                    // Guarded again here, though the query already excludes them: a row with no date
                    // is not a transaction, and the query and this loop must not disagree about it.
                    if ($bookedAt === null || $row->amount_minor === null) {
                        continue;
                    }

                    $tagIds = $tags->idsFor($row->tagNames());
                    $tagIdsByRow[$row->id] = $tagIds;

                    $inputs[] = new ImportedTransactionInput(
                        rowId: $row->id,
                        bookedAt: $bookedAt,
                        amountMinor: (int) $row->amount_minor,
                        currencyCode: (string) $row->currency_code,
                        description: (string) $row->description_ciphertext,
                        counterparty: (string) ($row->counterparty_ciphertext ?? ''),
                        counterpartyBlindIndex: $row->counterparty_blind_index,
                        fingerprint: $row->fingerprint,
                        importId: $import->id,
                        bankId: $import->bank_id,
                        categoryCode: $row->category_code,
                        countryCode: $row->country_code,
                        tagIds: $tagIds,
                    );
                }

                $written = $record($import->tax_subject_id, $import->account_id, $inputs);

                foreach ($rows as $row) {
                    $transactionId = $written[$row->id] ?? null;

                    if ($transactionId === null) {
                        continue;
                    }

                    $row->transaction_id = $transactionId;
                    $row->save();
                    $imported++;

                    // Remembered from what was actually saved, and as the person's own answer: they
                    // went through these rows, whatever proposed them first.
                    $rules->remember(
                        index: $row->counterparty_blind_index,
                        direction: MoneyDirection::ofAmount((int) $row->amount_minor),
                        categoryCode: $row->category_code,
                        countryCode: $row->country_code,
                        tagIds: $tagIdsByRow[$row->id] ?? [],
                        source: ProposalSource::PERSON,
                    );
                }
            });

        $import->imported_count = $imported;
        $import->status = StatementImportStatus::IMPORTED;
        $import->imported_at = now('UTC');
        $import->save();

        // Without this the screen sat on "Importing…" until someone refreshed it.
        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);

        $audit->record(AuditEventInput::occurringNow(
            subjectId: $import->tax_subject_id,
            actorId: $this->actorId,
            eventType: WalletAuditEvent::STATEMENT_IMPORTED->value,
            resourceType: WalletAuditEvent::STATEMENT_IMPORTED->resourceType(),
            resourceId: $import->id,
            // Counts and ids only. What the rows said is on the rows, encrypted, and a chain that
            // hashed a counterparty in clear would undo the reason it is encrypted at all.
            payloadHash: $audit->hashPayload([
                'account_id' => $import->account_id,
                'bank_id' => $import->bank_id,
                'row_count' => $import->row_count,
                'imported_count' => $imported,
                'duplicate_count' => $import->duplicate_count,
            ]),
        ));
    }
}
