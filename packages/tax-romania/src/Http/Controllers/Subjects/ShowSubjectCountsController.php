<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Subjects\Queries\SubjectCounts;

/** `GET /api/v1/subjects/counts` — `{active, ai_consented}` over every taxpayer the person works in now. */
final class ShowSubjectCountsController
{
    public function __invoke(Request $request, SubjectCounts $counts): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return response()->json($counts($user->id));
    }
}
