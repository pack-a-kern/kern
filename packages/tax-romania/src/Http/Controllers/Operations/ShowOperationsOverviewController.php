<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Operations;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Operations\DataTransferObjects\ScheduledTaskDefinition;
use Kern\TaxRomania\Operations\DataTransferObjects\TaskArgument;
use Kern\TaxRomania\Operations\Models\TaskRun;
use Kern\TaxRomania\Operations\Repositories\TaskRunRepository;
use Kern\TaxRomania\Operations\Services\ProbeSchedulerStatus;
use Kern\TaxRomania\Operations\Services\ScheduledTaskRegistry;

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
        $listed = array_values(array_filter($registry->all(), static fn (ScheduledTaskDefinition $definition): bool => $definition->task->isListed()));
        // The tasks on a clock — scheduled in code and given a time, the ones with a switch — first; each
        // part keeps the order the code lists them in (`usort` is stable).
        usort($listed, static fn (ScheduledTaskDefinition $a, ScheduledTaskDefinition $b): int => self::onAClock($b) <=> self::onAClock($a));

        return response()->json([
            'scheduler' => $scheduler()->toArray(),
            'tasks' => array_map(
                fn (ScheduledTaskDefinition $definition): array => $this->presentTask(
                    $definition,
                    $latest[$definition->task->value] ?? null,
                    $runs->isRunning($definition->task),
                ),
                $listed,
            ),
        ]);
    }

    private static function onAClock(ScheduledTaskDefinition $definition): bool
    {
        return $definition->task->isScheduled() && $definition->cronExpression !== null;
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
