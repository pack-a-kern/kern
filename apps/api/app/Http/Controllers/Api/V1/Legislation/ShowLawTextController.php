<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Legislation;

use App\Domain\TaxKnowledge\Queries\LegislationLibrary;
use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/legislation/laws/{law}/text?as_of=YYYY-MM-DD&page=1` — the law as it stood on a date.
 *
 * A page of articles at a time, each with its paragraphs, letters and tables: the whole Codul fiscal
 * is some 7 000 provisions, more than a page should carry at once. A date no consolidation covers —
 * before the law, or in a gap — answers 404, never the nearest text.
 */
final class ShowLawTextController extends Controller
{
    private const int PER_PAGE = 20;

    public function __invoke(string $law, Request $request, LegislationLibrary $library): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $asOf = is_string($validated['as_of'] ?? null) ? new DateTimeImmutable($validated['as_of']) : new DateTimeImmutable('today');
        $text = $library->text($law, $asOf, $request->integer('page', 1), self::PER_PAGE);

        abort_if($text === null, 404, 'No consolidation of this law covers that date.');

        return response()->json(['as_of' => $asOf->format('Y-m-d'), ...$text]);
    }
}
