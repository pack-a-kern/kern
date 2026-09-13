<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\DataTransferObjects\QueueJobSummary;
use App\Domain\Operations\Services\ProbeQueueStatus;
use App\Domain\Operations\Services\ReadQueueJobs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/operations/jobs` — the queue, as Horizon sees it.
 *
 * The counters come back with every page rather than from a second endpoint: they are the header of
 * the same screen, they are four Redis counts, and a pending total that lags the table it sits above
 * is worse than one recomputed for free.
 *
 * `status` selects which of Horizon's lists is read, and is not a filter over a merged one — see
 * {@see ReadQueueJobs} for why a Redis sorted set cannot answer a filtered page.
 */
final class ShowQueueJobsController extends Controller
{
    private const int DEFAULT_PAGE_SIZE = 25;

    /** Mirrors `PAGE_SIZES` in `resources/js/components/Pagination.tsx`. */
    private const array PAGE_SIZES = [10, 25, 50, 100];

    public function __invoke(Request $request, ReadQueueJobs $jobs, ProbeQueueStatus $queue): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(ReadQueueJobs::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
        ]);

        $page = $jobs->page(
            isset($validated['status']) ? (string) $validated['status'] : ReadQueueJobs::ALL,
            isset($validated['per_page']) ? (int) $validated['per_page'] : self::DEFAULT_PAGE_SIZE,
            isset($validated['page']) ? (int) $validated['page'] : 1,
        );

        return response()->json([
            ...$page,
            'jobs' => array_map(static fn (QueueJobSummary $job): array => $job->toArray(), $page['jobs']),
            'queue' => [
                ...$queue()->toArray(),
                // Horizon's own failure counter is replaced by the number of failures that still
                // have a job behind them — see ReadQueueJobs::retrievableTotal(). Reporting its
                // count here would put "15 failed" above a table that can only ever show 6, and
                // the nine it cannot show are not rows anyone could act on.
                'failed' => $jobs->retrievableTotal(ReadQueueJobs::FAILED),
            ],
        ]);
    }
}
