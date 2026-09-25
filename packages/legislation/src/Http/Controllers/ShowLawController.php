<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Legislation\Queries\LegislationLibrary;

/** `GET /api/v1/legislation/laws/{law}` — one law and every consolidation the portal lists, newest first. */
final class ShowLawController
{
    public function __invoke(string $law, LegislationLibrary $library): JsonResponse
    {
        $found = $library->law($law);

        abort_if($found === null, 404);

        return response()->json(['law' => $found]);
    }
}
