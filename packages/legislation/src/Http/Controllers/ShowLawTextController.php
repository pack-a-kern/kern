<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Queries\LegislationLibrary;

/**
 * `GET /api/v1/legislation/laws/{law}/text?as_of=YYYY-MM-DD&heading=…&provision=…` — the law as it
 * stood on a date.
 *
 * One part at a time — the title, chapter or section asked for by its path, or the one a provision
 * stands in by its citation — each article with its paragraphs, letters and tables: the whole Codul
 * fiscal is some 7 000 provisions, more than a page should carry at once. A date no consolidation
 * covers — before the law, or in a gap — answers 404, never the nearest text.
 */
final class ShowLawTextController
{
    public function __invoke(string $law, Request $request, LegislationLibrary $library): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'heading' => ['sometimes', 'string', 'max:500'],
            'provision' => ['sometimes', 'string', 'max:200'],
        ]);

        $asOf = is_string($validated['as_of'] ?? null) ? new DateTimeImmutable($validated['as_of']) : new DateTimeImmutable('today');
        $text = $library->text(
            $law,
            $asOf,
            is_string($validated['heading'] ?? null) ? $validated['heading'] : null,
            is_string($validated['provision'] ?? null) ? $validated['provision'] : null,
        );

        abort_if($text === null, 404, __('legislation::laws.no_consolidation_for_date'));

        return response()->json(['as_of' => $asOf->format('Y-m-d'), ...$text]);
    }
}
