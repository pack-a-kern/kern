<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Enums\OperationTask;
use App\Domain\Operations\Services\ScheduledTaskRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * `PUT /api/v1/operations/tasks/{task}` — pause or resume a scheduled task.
 *
 * `enabled` is the only field this endpoint accepts, and that is the design rather than an
 * unfinished version of it. The command is `OperationTask` (a closed enum) and the timing is
 * `config/operations.php` (env-overridable); a body that could set either would make this a
 * remote-code-execution surface or a way to silently stop a job forever with a mistyped cron
 * expression. Neither is reachable from here.
 *
 * Refused for a task the code does not declare schedulable: pausing something that was never on
 * the schedule would store a flag nothing reads, and the screen would then show a switch that does
 * nothing.
 *
 * Takes effect within a minute — `schedule:work` re-evaluates the schedule closure on every tick.
 */
final class UpdateScheduledTaskController
{
    public function __invoke(
        Request $request,
        string $task,
        ScheduledTaskRegistry $registry,
        AuditChainService $audit,
    ): JsonResponse {
        $operation = OperationTask::tryFrom($task);

        abort_if($operation === null, 404);
        abort_unless($operation->isScheduled(), 422, 'This task is not on the schedule.');

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $validated['enabled'];

        $before = $registry->find($operation)->enabled;

        $actorKey = $request->user()?->getKey();
        $actorId = is_scalar($actorKey) ? (string) $actorKey : null;

        $registry->setEnabled($operation, $enabled, $actorId);

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: 'SCHEDULED_TASK_TOGGLED',
            resourceType: 'operation_task',
            resourceId: $operation->value,
            payloadHash: $audit->hashPayload(['before' => $before, 'after' => $enabled]),
        ));

        return response()->json(['task' => $operation->value, 'enabled' => $enabled]);
    }
}
