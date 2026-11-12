<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Legislation\Http\Requests\ListLawsRequest;
use Kern\Legislation\Queries\LegislationLibrary;
use Kern\Platform\Routing\PageSizes;

/**
 * `GET /api/v1/legislation/laws` — one `page` of the laws read, `per_page` of them, with what the portal
 * says about each: of one `type`, one `year`, read in by one `watch`, each or any — how many those are in
 * all, and what may be filtered by.
 */
final class ListLawsController
{
    public function __invoke(ListLawsRequest $request, LegislationLibrary $library, PageSizes $sizes): JsonResponse
    {
        $page = $request->page();
        $perPage = $sizes->of($request->validated('per_page'));

        return response()->json([
            ...$library->laws($request->lawFilter(), $page, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'filters' => $library->filters($request->mayWatch()),
        ]);
    }
}
