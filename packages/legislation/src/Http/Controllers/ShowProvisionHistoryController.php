<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Queries\LegislationLibrary;

/** `GET /api/v1/legislation/laws/{law}/history?citation=Art. 170 alin. (2)` — one provision's wording over time. */
final class ShowProvisionHistoryController
{
    public function __invoke(string $law, Request $request, LegislationLibrary $library): JsonResponse
    {
        $validated = $request->validate(['citation' => ['required', 'string', 'max:255']]);

        return response()->json(['history' => $library->history($law, (string) $validated['citation'])]);
    }
}
