<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\DataTransferObjects\FreshnessItem;
use App\Domain\Operations\DataTransferObjects\ScheduledTaskDefinition;
use App\Domain\Operations\DataTransferObjects\SourceRegistryEntry;
use App\Domain\Operations\DataTransferObjects\TaskArgument;
use App\Domain\Operations\Models\JobRun;
use App\Domain\Operations\Repositories\JobRunRepository;
use App\Domain\Operations\Services\ProbeDataFreshness;
use App\Domain\Operations\Services\ProbeQueueStatus;
use App\Domain\Operations\Services\ProbeSchedulerStatus;
use App\Domain\Operations\Services\ReadSourceAllowlist;
use App\Domain\Operations\Services\ScheduledTaskRegistry;
use App\Domain\Settings\DataTransferObjects\ComponentHealth;
use App\Domain\Settings\Services\ProbeSystemHealth;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/operations` — everything the operations console renders in one request.
 *
 * One endpoint rather than six because these panels are read together and mean little apart: a
 * queue with no pending jobs is reassuring until you notice the scheduler heartbeat went stale
 * four hours ago, and the two arriving in separate responses invites rendering one before the
 * other. Each probe degrades independently inside its own service, so a single unreachable
 * dependency costs one panel, not the page.
 *
 * The system-health rows come from `ProbeSystemHealth`, the same service the settings screen uses.
 * They are surfaced here as well, deliberately duplicated rather than moved: reachability is the
 * first thing to check when an import has failed, and making an operator navigate to Settings to
 * find it is how a status board goes unread.
 *
 * `sources` is read-only, and that is a correctness property rather than a missing feature — see
 * {@see ReadSourceAllowlist} for why a switch over `official_sources.enabled` would have reported a
 * state it does not hold.
 */
final class ShowOperationsOverviewController extends Controller
{
    public function __invoke(
        ScheduledTaskRegistry $registry,
        JobRunRepository $runs,
        ProbeSchedulerStatus $scheduler,
        ProbeQueueStatus $queue,
        ProbeDataFreshness $freshness,
        ProbeSystemHealth $health,
        ReadSourceAllowlist $sources,
    ): JsonResponse {
        $latest = $runs->latestPerTask();

        return response()->json([
            'scheduler' => $scheduler()->toArray(),
            'queue' => $queue()->toArray(),
            'tasks' => array_map(
                fn (ScheduledTaskDefinition $definition): array => $this->presentTask(
                    $definition,
                    $latest[$definition->task->value] ?? null,
                    $runs->isRunning($definition->task),
                ),
                $registry->all(),
            ),
            'freshness' => array_map(
                static fn (FreshnessItem $item): array => $item->toArray(),
                $freshness(),
            ),
            'health' => array_map(
                static fn (ComponentHealth $component): array => $component->toArray(),
                $health(),
            ),
            'sources' => array_map(
                static fn (SourceRegistryEntry $entry): array => $entry->toArray(),
                $sources(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTask(ScheduledTaskDefinition $definition, ?JobRun $lastRun, bool $running): array
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
            'last_run' => $lastRun === null ? null : JobRunPresenter::present($lastRun),
        ];
    }
}
