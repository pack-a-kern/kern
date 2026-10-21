<?php

declare(strict_types=1);

namespace Kern\Audit\Http\Controllers\Audit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Audit\Queries\AuditEventQuery;
use Kern\Audit\Queries\AuditReach;

/**
 * `GET /api/v1/audit/events` — a page of the append-only audit trail, newest first.
 *
 * Behind the settings-admin gate: the trail names every actor and every resource touched, so it is
 * strictly more revealing than any single screen it records. The lines tied to a taxpayer are left out
 * for a reader without `audit.view.taxpayers` ({@see AuditReach}) — a `subject_id` filter included,
 * which then finds nothing rather than saying whether that taxpayer has a trail.
 *
 * `anchor` freezes the window being paged through; `AuditEventQuery` explains why a growing log
 * needs one before it can offer page numbers at all.
 */
final class ShowAuditEventsController
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

        $reach = AuditReach::for($request->user());

        return response()->json([...$query->page(
            $reach,
            [
                'q' => $validated['q'] ?? null,
                'event_type' => $validated['event_type'] ?? null,
                'subject_id' => $validated['subject_id'] ?? null,
                'since' => $validated['since'] ?? null,
                'anchor' => $validated['anchor'] ?? null,
            ],
            isset($validated['per_page']) ? (int) $validated['per_page'] : self::DEFAULT_PAGE_SIZE,
            isset($validated['page']) ? (int) $validated['page'] : 1,
        ), 'reach' => $reach->value]);
    }
}
