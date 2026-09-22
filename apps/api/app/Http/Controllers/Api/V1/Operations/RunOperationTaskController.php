<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Operations\DataTransferObjects\TaskArgument;
use App\Domain\Operations\Enums\OperationTask;
use App\Domain\Operations\Enums\TaskRunTrigger;
use App\Domain\Operations\Jobs\RunOperationTask;
use App\Domain\Operations\Services\OperationRunLock;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/operations/tasks/{task}/run` — start one background operation now.
 *
 * `{task}` is resolved through {@see OperationTask::tryFrom()}, so an unknown key is a 404 and no
 * request-supplied string ever reaches a command name. Arguments are validated against the task's
 * own {@see OperationTask::arguments()} declaration rather than against rules restated here, so the
 * form, the server and the job cannot drift apart.
 *
 * The lock is taken *here*, before dispatch, and handed to the job. That is what turns a double
 * click into an answer — 409, "already running" — instead of two concurrent imports racing on the
 * same `ext_source_document_versions` rows, which is a provenance defect rather than merely wasted
 * work (`BLUEPRINT.md` §7.1).
 *
 * Recorded in the audit chain as well as the run ledger, and the two are not redundant: the ledger
 * says what the system did, the chain says which administrator asked for it and cannot be edited
 * afterwards (§4.8).
 */
final class RunOperationTaskController extends Controller
{
    public function __invoke(
        Request $request,
        string $task,
        OperationRunLock $lock,
        AuditChainService $audit,
        ConfigRepository $config,
    ): JsonResponse {
        $operation = OperationTask::tryFrom($task);

        abort_if($operation === null, 404);

        $arguments = $this->validateArguments($request, $operation);

        $owner = $lock->acquire($operation);

        if ($owner === null) {
            return response()->json(
                ['message' => 'This task is already running.', 'task' => $operation->value],
                409,
            );
        }

        $actorKey = $request->user()?->getKey();
        $actorId = is_scalar($actorKey) ? (string) $actorKey : null;

        $queue = $config->get('operations.runs.queue');

        RunOperationTask::dispatch($operation, TaskRunTrigger::MANUAL, $actorId, $arguments, $owner)
            ->onQueue(is_string($queue) && $queue !== '' ? $queue : 'default');

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: 'OPERATION_TASK_RUN_REQUESTED',
            resourceType: 'operation_task',
            resourceId: $operation->value,
            payloadHash: $audit->hashPayload(['task' => $operation->value, 'arguments' => $arguments]),
        ));

        return response()->json(['task' => $operation->value, 'arguments' => $arguments], 202);
    }

    /**
     * Builds this task's validation rules from its own argument declaration, so the form and the
     * server cannot drift apart.
     *
     * Every rule constrains: a range, a closed set, or a calendar date. Nothing an operator types
     * can widen what the underlying command does — there is no free-text argument, and no address:
     * a law is named by its type, number and year, and only the allowlisted portal is ever asked.
     *
     * @return array<string, int|string>
     */
    private function validateArguments(Request $request, OperationTask $task): array
    {
        $rules = [];

        foreach ($task->arguments() as $argument) {
            $rules[$argument->name] = match ($argument->type) {
                TaskArgument::TYPE_INTEGER => [$argument->required ? 'required' : 'nullable', 'integer', "min:{$argument->min}", "max:{$argument->max}"],
                TaskArgument::TYPE_CHOICE => ['required', Rule::in($argument->options)],
                TaskArgument::TYPE_DATE => [$argument->required ? 'required' : 'nullable', 'date_format:Y-m-d'],
                default => ['prohibited'],
            };
        }

        if ($rules === []) {
            return [];
        }

        /** @var array<string, int|string|null> $validated */
        $validated = $request->validate($rules);

        // An optional argument left empty is not passed at all: the command's own default applies.
        return array_filter($validated, static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

}
