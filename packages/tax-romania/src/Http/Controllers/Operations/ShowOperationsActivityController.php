<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Operations;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Operations\Services\ProbeOperationsActivity;

/**
 * `GET /api/v1/operations/activity` — the live picture behind the topbar's queue-activity indicator:
 * the queues and their waiting counts, the named tasks running now, and whether the queue is moving.
 *
 * Light on purpose — it is polled while the indicator is on screen and asked again on each
 * `OperationsActivityChanged` nudge — and it carries only counts and task keys, never a job's payload,
 * for the same reason the Jobs tab does not (`BLUEPRINT.md` §4.10).
 */
final class ShowOperationsActivityController
{
    public function __invoke(ProbeOperationsActivity $activity): JsonResponse
    {
        // The poll cadence rides along so the screen need not be told it a second way: the one answer
        // carries both the picture and how soon to ask for it again.
        return response()->json($activity()->toArray() + [
            'poll_after_ms' => config()->integer('operations.activity.poll_after_ms'),
        ]);
    }
}
