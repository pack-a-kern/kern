<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Domain\Audit\Queries\AuditEventQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/audit/events` — a page of the append-only audit trail, newest first.
 *
 * Behind the settings-admin gate: the trail names every actor and every resource touched across
 * all tax subjects, so it is strictly more revealing than any single screen it records.
 */
final class ShowAuditEventsController extends Controller
{
    private const int PAGE_SIZE = 25;

    public function __invoke(Request $request, AuditEventQuery $query): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'event_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'uuid'],
            'since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($query->page([
            'q' => $validated['q'] ?? null,
            'event_type' => $validated['event_type'] ?? null,
            'subject_id' => $validated['subject_id'] ?? null,
            'since' => $validated['since'] ?? null,
            'before_sequence' => isset($validated['cursor']) ? (int) $validated['cursor'] : null,
        ], self::PAGE_SIZE));
    }
}
