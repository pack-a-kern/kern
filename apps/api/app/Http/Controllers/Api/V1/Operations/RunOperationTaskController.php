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
 * same `source_document_versions` rows, which is a provenance defect rather than merely wasted
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

        $arguments = $this->validateArguments($request, $operation, $config);

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
     * Every rule constrains: a range, a closed set, or an allowlisted host. Nothing an operator
     * types can widen what the underlying command does — a URL is rejected here before dispatch if
     * its host is not one `config/sources.php` names for the chosen source, and rejected again by
     * `AllowlistedHttpFetcher` on every redirect hop if it somehow got past.
     *
     * @return array<string, int|string>
     */
    private function validateArguments(Request $request, OperationTask $task, ConfigRepository $config): array
    {
        $rules = [];

        foreach ($task->arguments() as $argument) {
            $rules[$argument->name] = match ($argument->type) {
                TaskArgument::TYPE_INTEGER => ['required', 'integer', "min:{$argument->min}", "max:{$argument->max}"],
                TaskArgument::TYPE_CHOICE => ['required', Rule::in($argument->options)],
                TaskArgument::TYPE_URL => ['required', 'string', 'max:2048', 'url:https'],
                default => ['prohibited'],
            };
        }

        if ($rules === []) {
            return [];
        }

        /** @var array<string, int|string> $validated */
        $validated = $request->validate($rules);

        $this->assertUrlsAreAllowlisted($task, $validated, $config);

        return $validated;
    }

    /**
     * A URL argument is only meaningful next to the source it will be fetched from, so the host
     * check is a second pass rather than a per-field rule: it needs the already-validated source
     * code from the same payload.
     *
     * @param  array<string, int|string>  $validated
     */
    private function assertUrlsAreAllowlisted(OperationTask $task, array $validated, ConfigRepository $config): void
    {
        foreach ($task->arguments() as $argument) {
            if ($argument->type !== TaskArgument::TYPE_URL) {
                continue;
            }

            $url = $validated[$argument->name] ?? null;
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            abort_if(
                ! is_string($host) || $host === '' || ! in_array(strtolower($host), $this->allowedHosts($validated, $config), true),
                422,
                'That URL is not on the allowlist for the chosen source.',
            );
        }
    }

    /**
     * The hosts `config/sources.php` names for the source in this payload. Read from config rather
     * than through `Domain/TaxKnowledge`, the same crossing every other read in this module uses.
     *
     * @param  array<string, int|string>  $validated
     * @return list<string>
     */
    private function allowedHosts(array $validated, ConfigRepository $config): array
    {
        $source = $validated['source'] ?? null;
        $hosts = $config->get('sources.entries.' . (is_string($source) ? $source : '') . '.allowed_hosts');

        if (! is_array($hosts)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $host): string => is_string($host) ? strtolower($host) : '',
            $hosts,
        ));
    }
}
