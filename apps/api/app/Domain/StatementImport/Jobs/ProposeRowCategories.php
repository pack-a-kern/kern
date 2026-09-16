<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Jobs;

use App\Domain\StatementImport\DataTransferObjects\AiAnswer;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Events\StatementImportChanged;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\StatementImport\Services\ClassifyCounterparties;
use App\Domain\StatementImport\Services\StatementAiClient;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Domain\Subjects\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Decides which counterparties still need an answer, and hands each batch of them to its own job.
 *
 * ## Why this does not make the calls itself
 *
 * It used to, in a loop, on the `default` queue — whose worker kills anything running past sixty
 * seconds. Eight calls to a local model take minutes, so the job was killed partway: two thirds of a
 * statement classified, the rest silently not, and a failed job nobody saw. Each call is now its own
 * {@see ProposeCategoryBatch} on the queue built for model calls, with a timeout sized for one call —
 * so a smaller batch size means more jobs, never a longer one.
 *
 * ## What it records
 *
 * How many batches to expect, under a run token. The screen shows progress against that number, and
 * a batch from an earlier run — the person changed the columns meanwhile, and the rows it was asked
 * about no longer exist — sees the token has moved and does nothing.
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
        ClassifyCounterparties $classifier,
        StatementAiClient $ai,
        TenantContext $tenant,
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
         * Tags and remembered counterparty answers are subject-scoped, and a queue worker acts for
         * nobody until someone is bound. No actor means no proposals rather than a failed import:
         * the rows are already staged and answerable by hand. Re-checked rather than assumed,
         * because minutes pass between confirming the form and this running.
         */
        if ($this->actorId === null) {
            return;
        }

        $tenant->authorizeAndActivate($this->actorId, $import->tax_subject_id);

        $run = (string) Str::uuid();

        if (! $ai->isAvailable($import->tax_subject_id)) {
            $import->startClassification($run, 0, AiAnswer::UNAVAILABLE);
            StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status, true);

            return;
        }

        /** @var list<StatementImportRow> $rows */
        $rows = StatementImportRow::withoutGlobalScope(TaxSubjectScope::class)
            ->where('import_id', $import->id)
            // Rows a remembered answer already covers are left alone: a person's own earlier decision
            // outranks anything a model would say now, and costs no call.
            ->whereNull('category_code')
            ->whereNotNull('amount_minor')
            ->orderBy('row_number')
            ->get()
            ->all();

        $batches = $classifier->batches($rows);

        $import->startClassification($run, count($batches));

        foreach ($batches as $rowIds) {
            ProposeCategoryBatch::dispatch($import->id, $this->actorId, $run, $rowIds);
        }

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status, true);
    }
}
