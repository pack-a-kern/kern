<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Routing\PageSizes;
use Kern\TaxRomania\Subjects\Queries\ListSubjectsForUser;
use Kern\TaxRomania\Subjects\Queries\SubjectSummary;

/**
 * `GET /api/v1/subjects` — every `TaxSubject` the authenticated user currently holds a
 * non-revoked membership for, cursor-paginated (`BLUEPRINT.md` §18). This is the real listing
 * `docs/handoff/030-pwa-frontend.md`'s dashboard "Subiect fiscal activ" panel should call instead
 * of its `localStorage`-only workaround (that handoff's Key decision #1).
 */
final class ListSubjectsController
{
    public function __invoke(Request $request, ListSubjectsForUser $listSubjects, PageSizes $sizes): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => $sizes->rule()]);
        $page = isset($validated['page']) ? max(1, (int) $validated['page']) : 1;
        $perPage = $sizes->of($validated['per_page'] ?? null);

        $paginator = $listSubjects($user->id, $page, $perPage)
            ->through(static fn (SubjectSummary $summary): array => $summary->toArray());

        return response()->json($paginator);
    }
}
