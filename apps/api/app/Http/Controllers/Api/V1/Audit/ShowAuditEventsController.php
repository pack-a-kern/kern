<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Audit\Queries\AuditEventQuery;

/**
 * `GET /api/v1/audit/events` — a page of the append-only audit trail, newest first.
 *
 * Behind the settings-admin gate: the trail names every actor and every resource touched across
 * all tax subjects, so it is strictly more revealing than any single screen it records.
 *
 * `anchor` freezes the window being paged through; `AuditEventQuery` explains why a growing log
 * needs one before it can offer page numbers at all.
 */
final class ShowAuditEventsController extends Controller
{
    private const int DEFAULT_PAGE_SIZE = 25;

    /** The sizes the screen's pager offers — mirrors `PAGE_SIZES` in `resources/js`. */
    private const array PAGE_SIZES = [10, 25, 50, 100];

    public function __invoke(Request $request, AuditEventQuery $query): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'event_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'uuid'],
            'since' => ['nullable', 'date'],
            'anchor' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
        ]);

        return response()->json($query->page(
            [
                'q' => $validated['q'] ?? null,
                'event_type' => $validated['event_type'] ?? null,
                'subject_id' => $validated['subject_id'] ?? null,
                'since' => $validated['since'] ?? null,
                'anchor' => $validated['anchor'] ?? null,
            ],
            isset($validated['per_page']) ? (int) $validated['per_page'] : self::DEFAULT_PAGE_SIZE,
            isset($validated['page']) ? (int) $validated['page'] : 1,
        ));
    }
}
