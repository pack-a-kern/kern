<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\DataTransferObjects\ActivityEntry;
use App\Domain\Operations\Services\ReadBackgroundActivity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/operations/activity` — task runs and failed queue jobs as one feed.
 *
 * Replaces the separate `runs` and `failed-jobs` listings. They were two tables of mostly red rows
 * sitting one above the other, which read as duplication even though the records differ; a reader
 * looking for "what went wrong in the background" had to check both and knew of no reason why.
 *
 * The rows keep their differences — `kind` says which record a line came from, and the fields only
 * one source has stay null on the other — because that is what decides the row's action. Retrying a
 * failed job and running a task again are different operations against different endpoints.
 *
 * `failed-jobs/{uuid}/retry` and `failed-jobs/{uuid}` are unaffected; only the listing merged.
 */
final class ShowOperationsActivityController extends Controller
{
    public function __invoke(Request $request, ReadBackgroundActivity $activity): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'failed', 'succeeded', 'running'])],
            'kind' => ['sometimes', Rule::in(['all', ActivityEntry::KIND_TASK_RUN, ActivityEntry::KIND_FAILED_JOB])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $result = $activity->page(
            status: is_string($validated['status'] ?? null) ? $validated['status'] : 'all',
            kind: is_string($validated['kind'] ?? null) ? $validated['kind'] : 'all',
            page: is_int($validated['page'] ?? null) ? $validated['page'] : 1,
        );

        return response()->json([
            'activity' => array_map(
                static fn (ActivityEntry $entry): array => $entry->toArray(),
                $result['entries'],
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
        ]);
    }
}
