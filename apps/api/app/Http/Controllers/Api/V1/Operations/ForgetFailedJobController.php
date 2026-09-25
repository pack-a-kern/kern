<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Repositories\FailedJobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * `DELETE /api/v1/operations/failed-jobs/{uuid}` — discard one failed job without re-running it.
 *
 * Destructive and irreversible: the payload is the only record of what that job was going to do.
 * Recorded in the audit chain before the row is removed, so "who dismissed the failure" survives
 * the row itself.
 */
final class ForgetFailedJobController
{
    public function __invoke(
        Request $request,
        string $uuid,
        FailedJobRepository $failed,
        AuditChainService $audit,
    ): JsonResponse {
        abort_unless($failed->exists($uuid), 404);

        $actorKey = $request->user()?->getKey();
        $actorId = is_scalar($actorKey) ? (string) $actorKey : null;

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: 'FAILED_JOB_FORGOTTEN',
            resourceType: 'failed_job',
            resourceId: $uuid,
            payloadHash: $audit->hashPayload(['uuid' => $uuid]),
        ));

        $failed->forget($uuid);

        return response()->json(['uuid' => $uuid]);
    }
}
