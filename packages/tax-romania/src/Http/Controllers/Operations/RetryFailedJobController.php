<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Operations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Operations\Repositories\FailedJobRepository;

/**
 * `POST /api/v1/operations/failed-jobs/{uuid}/retry` — put one failed job back on its queue.
 *
 * Re-running a job re-executes whatever it did: a document extraction sends a document to a
 * provider again, an import writes again. It is therefore a write operation gated by
 * `operations.run`, not something a read-only operator can trigger, and it is recorded in the
 * audit chain like any other administrative act.
 */
final class RetryFailedJobController
{
    public function __invoke(
        Request $request,
        string $uuid,
        FailedJobRepository $failed,
        AuditChainService $audit,
    ): JsonResponse {
        abort_unless($failed->exists($uuid), 404);

        /*
         * A failure the screen lists is not always one that can be re-run.
         *
         * Horizon's record is a description of what happened; `queue:retry` rebuilds the job from the
         * `failed_jobs` row, and when a previous retry or a `queue:flush` removed that row the payload is
         * gone with it. Without this the request 404'd — "not found", for a failure plainly on screen —
         * and the reader was left to guess which of the two things was missing.
         */
        if (! $failed->isRetryable($uuid)) {
            return response()->json([
                'message' => 'This failure is only in the queue monitor\'s history; the job itself was not '
                    . 'kept, so there is nothing to re-run. Discard it to clear it from the list.',
                'reason' => 'FAILED_JOB_NOT_REPLAYABLE',
            ], 409);
        }

        $failed->retry($uuid);

        $actorKey = $request->user()?->getKey();
        $actorId = is_scalar($actorKey) ? (string) $actorKey : null;

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: 'FAILED_JOB_RETRIED',
            resourceType: 'failed_job',
            resourceId: $uuid,
            payloadHash: $audit->hashPayload(['uuid' => $uuid]),
        ));

        return response()->json(['uuid' => $uuid], 202);
    }
}
