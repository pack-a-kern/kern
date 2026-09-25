<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Jobs;

use App\Domain\StatementImport\Enums\ImportNoticeKind;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Events\StatementImportChanged;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\StatementImport\Services\ClassifyCounterparties;
use App\Domain\StatementImport\Services\NotifyImportOwner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Tenancy\TenantScope;
use Throwable;

/**
 * One call to the model: a batch of counterparties, and their answers written onto the rows.
 *
 * ## Why only ids travel
 *
 * The queue holds row ids, not names. What gets sent is rebuilt here from the rows themselves, so a
 * person's counterparties sit encrypted in the database and never in plain text in Redis.
 *
 * ## Why it runs where it runs
 *
 * On the model queue (`ai.providers.queue`), with a timeout sized for one call. One process
 * serves that queue, because the constraint is one model endpoint — which also means batches from one
 * statement run one after another and never race each other's writes.
 *
 * ## Failing still says something
 *
 * A call that times out or errors is recorded as a batch that failed, so the screen stops waiting for
 * it and says so, instead of showing "waiting for suggestions" forever.
 */
final class ProposeCategoryBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout;

    /** The recorded failure when the job itself died rather than the call returning an error. */
    private const string JOB_FAILED = 'JOB_FAILED';

    /**
     * @param  list<string>  $rowIds
     */
    public function __construct(
        private readonly string $importId,
        private readonly string $actorId,
        private readonly string $run,
        private readonly array $rowIds,
    ) {
        $this->timeout = config()->integer('ai.statements.timeout_seconds')
            + config()->integer('ai.providers.job_timeout_margin_seconds');
        $this->onQueue(config()->string('ai.providers.queue'));
    }

    public function handle(ClassifyCounterparties $classifier, TenantContext $tenant, NotifyImportOwner $notify): void
    {
        $import = $this->import();

        if ($import === null) {
            return;
        }

        $tenant->authorizeAndActivate($this->actorId, $import->tax_subject_id);

        /** @var list<StatementImportRow> $rows */
        $rows = StatementImportRow::withoutGlobalScope(TenantScope::class)
            ->where('import_id', $import->id)
            ->whereKey($this->rowIds)
            // Answered by a person while this waited in the queue: theirs stands.
            ->whereNull('category_code')
            ->whereNotNull('amount_minor')
            ->orderBy('row_number')
            ->get()
            ->all();

        $import->recordClassificationBatch($this->run, $classifier->classify($import->tax_subject_id, $rows));

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status, true);

        // The last call of the round: the rows are ready to go through, whoever is still watching.
        if ($import->classificationFinished()) {
            $notify($import, ImportNoticeKind::READY_FOR_REVIEW);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $import = $this->import();

        if ($import === null) {
            return;
        }

        $import->recordClassificationBatch($this->run, ['sent' => null, 'reply' => null, 'failure' => self::JOB_FAILED]);

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status, true);

        // A failed last call still ends the round: the review is open, with what did come back.
        if ($import->classificationFinished()) {
            app(NotifyImportOwner::class)($import, ImportNoticeKind::READY_FOR_REVIEW);
        }
    }

    /** The import this batch belongs to, while it is still the run this batch was made for. */
    private function import(): ?StatementImport
    {
        $import = StatementImport::withoutGlobalScope(TenantScope::class)->find($this->importId);

        if ($import === null || $import->status !== StatementImportStatus::REVIEW) {
            return null;
        }

        return $import->isClassificationRun($this->run) ? $import : null;
    }
}
