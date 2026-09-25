<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subjects;

use App\Domain\Subjects\Queries\ListSubjectsForUser;
use App\Domain\Subjects\Queries\SubjectSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/subjects` — every `TaxSubject` the authenticated user currently holds a
 * non-revoked membership for, cursor-paginated (`BLUEPRINT.md` §18). This is the real listing
 * `docs/handoff/030-pwa-frontend.md`'s dashboard "Subiect fiscal activ" panel should call instead
 * of its `localStorage`-only workaround (that handoff's Key decision #1).
 */
final class ListSubjectsController
{
    public function __invoke(Request $request, ListSubjectsForUser $listSubjects): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        $paginator = $listSubjects($user->id, $page, $perPage)
            ->through(static fn (SubjectSummary $summary): array => $summary->toArray());

        return response()->json($paginator);
    }
}
