<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Legislation\Queries\LegislationLibrary;

/** `GET /api/v1/legislation/laws` — every law read, with what the portal says about it. */
final class ListLawsController
{
    public function __invoke(LegislationLibrary $library): JsonResponse
    {
        return response()->json(['laws' => $library->laws()]);
    }
}
