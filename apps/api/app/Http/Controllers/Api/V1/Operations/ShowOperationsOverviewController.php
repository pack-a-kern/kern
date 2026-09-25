<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\DataTransferObjects\ScheduledTaskDefinition;
use App\Domain\Operations\DataTransferObjects\TaskArgument;
use App\Domain\Operations\Models\TaskRun;
use App\Domain\Operations\Repositories\TaskRunRepository;
use App\Domain\Operations\Services\ProbeSchedulerStatus;
use App\Domain\Operations\Services\ScheduledTaskRegistry;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/operations` — the scheduled-task console.
 *
 * The six tasks and the scheduler's heartbeat, and nothing else. They are one answer: a task that
 * reports "last run succeeded" means nothing if the heartbeat went stale four hours ago, and the two
 * arriving in separate responses invites rendering one before the other.
 *
 * What used to be here and is not any more, so nobody goes looking for it:
 *
 *  - **the queue and its jobs** moved to `GET /v1/operations/jobs`, which reads Horizon. Queued jobs
 *    and operation tasks are different populations — a task reaches the queue wrapped in one job
 *    class and records its own outcome, so it never appears among the failures;
 *  - **service health** was a second copy of the settings screen's, and is now only there;
 *  - **data freshness** and the **official-source allowlist** moved to
 *    `GET /v1/settings/platform-data`, behind the settings permission, because what this
 *    installation is configured to trust is a settings question rather than an operations one.
 *
 * Each probe degrades inside its own service, so an unreachable dependency costs one panel rather
 * than the page.
 */
final class ShowOperationsOverviewController
{
    public function __invoke(
        ScheduledTaskRegistry $registry,
        TaskRunRepository $runs,
        ProbeSchedulerStatus $scheduler,
    ): JsonResponse {
        $latest = $runs->latestPerTask();

        return response()->json([
            'scheduler' => $scheduler()->toArray(),
            'tasks' => array_map(
                fn (ScheduledTaskDefinition $definition): array => $this->presentTask(
                    $definition,
                    $latest[$definition->task->value] ?? null,
                    $runs->isRunning($definition->task),
                ),
                array_values(array_filter($registry->all(), static fn (ScheduledTaskDefinition $definition): bool => $definition->task->isListed())),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTask(ScheduledTaskDefinition $definition, ?TaskRun $lastRun, bool $running): array
    {
        return [
            'key' => $definition->task->value,
            'command' => $definition->task->command(),
            'scheduled' => $definition->isScheduled(),
            'schedulable' => $definition->task->isScheduled(),
            'read_only' => $definition->task->isReadOnly(),
            'cron_expression' => $definition->cronExpression,
            'timezone' => $definition->timezone,
            'enabled' => $definition->enabled,
            'running' => $running,
            'arguments' => array_map(
                static fn (TaskArgument $argument): array => $argument->toArray(),
                $definition->task->arguments(),
            ),
            'last_run' => $lastRun === null ? null : TaskRunPresenter::present($lastRun),
        ];
    }
}
