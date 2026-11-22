<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Kern\Platform\Settings\DataTransferObjects\AiTaskModel;
use Kern\Platform\Settings\Services\AiTaskModels;

/**
 * `GET /api/v1/settings/ai/task-models` — every AI task and the model it will use.
 *
 * A read of this installation's own configuration: which model answers each kind of work, and
 * whether that is the task's own model or the base one it inherits. No secret and no network — the
 * provider is not reached here; {@see VerifyAiTaskModelsController} is the button that does that.
 */
final class ShowAiTaskModelsController
{
    public function __invoke(AiTaskModels $taskModels): JsonResponse
    {
        return response()->json([
            'tasks' => array_map(
                static fn (AiTaskModel $task): array => $task->toArray(),
                $taskModels->all(),
            ),
        ]);
    }
}
