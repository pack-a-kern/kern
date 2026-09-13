<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Models\TaskRun;

/**
 * One JSON shape for a run row, shared by the overview (which embeds the latest run per task) and
 * the history endpoint.
 *
 * A separate class rather than a method on {@see TaskRun}: the model is a domain object, and how it
 * is rendered over HTTP is this layer's concern (`docs/AGENTS.md` §6). Duplicating the shape across
 * the two controllers instead would let them drift, and "the last run" on the task list and "the
 * same run" in the history must never disagree.
 */
final class TaskRunPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(TaskRun $run): array
    {
        return [
            'id' => $run->id,
            'task_key' => $run->task_key,
            'trigger' => $run->trigger->value,
            'status' => $run->status->value,
            'triggered_by' => $run->triggered_by,
            'arguments' => $run->arguments,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_seconds' => $run->durationSeconds(),
            'exit_code' => $run->exit_code,
            'output' => $run->output,
        ];
    }
}
