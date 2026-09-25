<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Repositories\FailedJobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

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
