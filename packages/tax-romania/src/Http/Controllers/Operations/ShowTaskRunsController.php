<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Operations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Routing\PageSizes;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Operations\Models\TaskRun;

/**
 * `GET /api/v1/operations/tasks/{task}/runs` — one task's history.
 *
 * Per task rather than a flat list of every run: the task list already answers "did the last one
 * work", and the only question left is "what happened the other times *this* task ran". A combined
 * table would repeat the status column that is already one click away and bury the six tasks in
 * each other's history.
 *
 * `{task}` is resolved against the enum, so an unknown key is a 404 rather than an empty page that
 * looks like a task which has never run.
 */
final class ShowTaskRunsController
{
    public function __invoke(Request $request, string $task, PageSizes $sizes): JsonResponse
    {
        $resolved = OperationTask::tryFrom($task);
        abort_if($resolved === null, 404);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => $sizes->rule(),
        ]);

        $perPage = $sizes->of($validated['per_page'] ?? null);
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;

        $query = TaskRun::query()->where('task_key', $resolved->value);
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max($page, 1), $pages);

        /** @var list<TaskRun> $runs */
        $runs = $query->orderByDesc('started_at')->forPage($page, $perPage)->get()->all();

        return response()->json([
            'runs' => array_map(TaskRunPresenter::present(...), $runs),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }
}
