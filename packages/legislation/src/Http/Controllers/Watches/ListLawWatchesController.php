<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Watches\WatchSettings;

/**
 * `GET /api/v1/legislation/watches` — every watch, the packages' first, and the intervals a person may
 * choose. Few by nature — a handful declared, a few kept by hand — so answered whole.
 */
final class ListLawWatchesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $viewer = $request->user()?->getAuthIdentifier();

        return response()->json([
            'watches' => LawWatch::query()->orderByRaw('declared_by IS NULL')->orderBy('search_words')->get()
                ->map(static fn (LawWatch $watch): array => LawWatchView::of($watch, is_scalar($viewer) ? (string) $viewer : null))
                ->all(),
            'intervals' => WatchSettings::read(config())->intervals,
        ]);
    }
}
