<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\StatementImport\Enums\ImportNoticeKind;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Events\StatementImportChanged;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\StatementImport\Services\NameStatementFile;
use Kern\TaxRomania\StatementImport\Services\NotifyImportOwner;
use Kern\TaxRomania\Wallet\Actions\RecordImportedTransactions;
use Kern\TaxRomania\Wallet\DataTransferObjects\ImportedTransactionInput;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;
use Kern\TaxRomania\Wallet\Services\TagDirectory;
use Throwable;

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

    /**
     * One try. A large import stopped by a timeout is a half-written import for an operator to look
     * at, not something to hand straight back to a worker: a second run racing the first could write
     * a transaction twice. The queue's `retry_after` sits a minute past this job's own timeout for
     * the same reason — a slow write is never reclaimed while it is still running.
     *
     * @var int
     */
    public $tries = 1;

    /** The commit queue's own timeout — generous for a large statement whose every row is sealed and indexed. */
    public ?int $timeout = null;

    public function __construct(
        private readonly string $importId,
        private readonly ?string $actorId = null,
    ) {
        // Its own long-running queue, not the general one whose worker stops a job at sixty seconds —
        // too short for an import that encrypts and blind-indexes every row (`statement_import.commit`).
        $this->timeout = config()->integer('statement_import.commit.timeout_seconds');
        $this->onQueue(config()->string('statement_import.commit.queue'));
    }

    public function handle(
        RecordImportedTransactions $record,
        TagDirectory $tags,
        CounterpartyDirectory $rules,
        AuditChainService $audit,
        TenantContext $tenant,
        ConfigRepository $config,
        NotifyImportOwner $notify,
        NameStatementFile $name,
    ): void {
        $import = StatementImport::withoutGlobalScope(TenantScope::class)->find($this->importId);

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
            $notify($import, ImportNoticeKind::FAILED);

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

                /*
                 * The person's answers, one per counterparty and direction — not one per row.
                 *
                 * A statement names the same shop on thirty lines, and remembering it is a rule row
                 * written and its tags rewritten inside a transaction: thirty transactions for one
                 * answer that does not change between them. Collected here last-wins — the rows are
                 * in `row_number` order, so the last line for a counterparty is the one that stands,
                 * exactly as remembering each in turn would have left it — and applied once each below.
                 *
                 * @var array<string, array{index: string, counterparty: string|null, direction: MoneyDirection, categoryCode: string|null, countryCode: string|null, tagIds: list<string>}> $answers
                 */
                $answers = [];

                foreach ($rows as $row) {
                    $transactionId = $written[$row->id] ?? null;

                    if ($transactionId === null) {
                        continue;
                    }

                    $row->transaction_id = $transactionId;
                    $row->save();
                    $imported++;

                    // A row with no counterparty index is one `remember()` would refuse anyway — its
                    // name and index arrive as a pair or not at all — so it never reaches the map.
                    if ($row->counterparty_blind_index === null) {
                        continue;
                    }

                    $direction = MoneyDirection::ofAmount((int) $row->amount_minor);

                    $answers[$row->counterparty_blind_index . '|' . $direction->value] = [
                        'index' => $row->counterparty_blind_index,
                        'counterparty' => $row->counterparty_ciphertext,
                        'direction' => $direction,
                        'categoryCode' => $row->category_code,
                        'countryCode' => $row->country_code,
                        'tagIds' => $tagIdsByRow[$row->id] ?? [],
                    ];
                }

                // Remembered from what was actually saved, and as the person's own answer: they went
                // through these rows, whatever proposed them first.
                foreach ($answers as $answer) {
                    $rules->remember(
                        index: $answer['index'],
                        counterparty: $answer['counterparty'],
                        direction: $answer['direction'],
                        categoryCode: $answer['categoryCode'],
                        countryCode: $answer['countryCode'],
                        tagIds: $answer['tagIds'],
                        source: ProposalSource::PERSON,
                    );
                }
            });

        $import->imported_count = $imported;
        $import->status = StatementImportStatus::IMPORTED;
        $import->imported_at = now('UTC');
        $import->save();

        // The file can be named now that the bank and the period are known, rather than sitting in
        // the documents list under a hash for the rest of its life.
        $name($import);

        // Without this the screen sat on "Importing…" until someone refreshed it.
        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);
        $notify($import, ImportNoticeKind::IMPORTED);

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

    /**
     * The write stopped part-way.
     *
     * Rows are written in chunks, each committed on its own, so a crash can leave some transactions
     * in the ledger. The import says how many, and is marked failed rather than left IMPORTING for
     * ever — where nothing would move it on, and nothing could undo what it had already written.
     * Undo accepts a failed import that holds transactions for exactly this case.
     */
    public function failed(?Throwable $exception): void
    {
        $import = StatementImport::withoutGlobalScope(TenantScope::class)->find($this->importId);

        if ($import === null || $import->status !== StatementImportStatus::IMPORTING) {
            return;
        }

        $import->imported_count = StatementImportRow::withoutGlobalScope(TenantScope::class)
            ->where('import_id', $import->id)
            ->whereNotNull('transaction_id')
            ->count();
        $import->status = StatementImportStatus::FAILED;
        $import->failure_reason = 'WRITE_FAILED';
        $import->save();

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);
        app(NotifyImportOwner::class)($import, ImportNoticeKind::FAILED);
    }
}
