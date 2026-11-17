<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Operations;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Operations\Services\ProbeOperationsActivity;

/**
 * `GET /api/v1/operations/activity` — the live picture behind the topbar's queue-activity indicator:
 * the queues and their waiting counts, the named tasks running now, and whether the queue is moving.
 *
 * Light on purpose — it is asked again on each `OperationsActivityChanged` nudge, and polled only
 * while work is in flight — and it carries only counts and task keys, never a job's payload, for the
 * same reason the Jobs tab does not (`BLUEPRINT.md` §4.10).
 */
final class ShowOperationsActivityController
{
    public function __invoke(ProbeOperationsActivity $activity): JsonResponse
    {
        $snapshot = $activity();

        // The poll cadence rides along so the screen need not be told it a second way. It is sent only
        // while something is moving: once the queue falls idle the answer carries `null`, the screen
        // stops polling, and the socket alone brings it back when the next task starts (ADR 0024).
        return response()->json($snapshot->toArray() + [
            'poll_after_ms' => $snapshot->isBusy() ? config()->integer('operations.activity.poll_after_ms') : null,
        ]);
    }
}
