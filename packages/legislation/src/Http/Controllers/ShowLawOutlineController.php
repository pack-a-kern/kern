<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Queries\LegislationLibrary;

/**
 * `GET /api/v1/legislation/laws/{law}/outline?as_of=YYYY-MM-DD` — the law's table of contents on a
 * date: titles, chapters and sections, each with the articles under it.
 */
final class ShowLawOutlineController
{
    public function __invoke(string $law, Request $request, LegislationLibrary $library): JsonResponse
    {
        $validated = $request->validate(['as_of' => ['sometimes', 'date_format:Y-m-d']]);

        $asOf = is_string($validated['as_of'] ?? null) ? new DateTimeImmutable($validated['as_of']) : new DateTimeImmutable('today');
        $outline = $library->outline($law, $asOf);

        abort_if($outline === null, 404, 'No consolidation of this law covers that date.');

        return response()->json(['as_of' => $asOf->format('Y-m-d'), ...$outline]);
    }
}
