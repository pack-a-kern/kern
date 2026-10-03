<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\StatementImport\Services\ClassifyKnownCounterparties;

/**
 * One call to a model about one taxpayer's counterparties, on the queue that expects slow calls.
 *
 * ## Why this exists rather than the command doing the work
 *
 * It did, and the command runs inside `RunOperationTask` on the `default` queue, whose timeout is
 * sixty seconds and whose configuration says in as many words: *"Model calls do not run here:
 * anything slow belongs on the model queue above."* One batch is a hundred counterparties in a
 * single call, measured at over three minutes, so the worker killed it every ten minutes without it
 * ever having written anything.
 *
 * So the scheduled command dispatches and this runs — on `ai.providers.queue`, with a timeout sized
 * for one call, which is exactly the shape {@see ProposeCategoryBatch} already had for the same
 * question asked during an import.
 *
 * ## One batch, not one taxpayer
 *
 * A taxpayer with three hundred unanswered names is three calls of several minutes. As one job, a
 * timeout during the third would throw away the first two and leave nothing saying why. As three
 * jobs, each one either finishes or fails alone.
 *
 * ## The actor
 *
 * Reading the worklist needs only the subject named; writing the answer needs a bound tenant,
 * because the tags it reuses and the counterparty it updates are both subject-scoped on write. So
 * the job carries whoever the command found to act for this taxpayer, and binds through the same
 * gate a request goes through. Everything it writes is marked `AI`.
 */
final class ClassifyCounterpartyBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt. A model that refused once will refuse again in a minute, and every retry is
     * another few minutes of a queue that serves one endpoint.
     *
     * @var int
     */
    public $tries = 1;

    public int $timeout;

    /** @param list<string> $counterpartyIds */
    public function __construct(
        private readonly string $subjectId,
        private readonly string $actorId,
        private readonly array $counterpartyIds,
    ) {
        // The call's own timeout plus the margin, so the worker never kills a request that is still
        // within the time the client was told it could take.
        $this->timeout = config()->integer('ai.statements.timeout_seconds')
            + config()->integer('ai.providers.job_timeout_margin_seconds');
        $this->onQueue(config()->string('ai.providers.queue'));
    }

    public function handle(ClassifyKnownCounterparties $classifier, TenantContext $tenant): void
    {
        $tenant->authorizeAndActivate($this->actorId, $this->subjectId);

        $classifier->classifyBatch($this->subjectId, $this->counterpartyIds);
    }
}
