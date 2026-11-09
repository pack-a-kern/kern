<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Watches\WatchSettings;

/**
 * `PATCH /api/v1/legislation/watches/{id}` — paused or resumed; a person's own watch also run more or less
 * often. A package's watch keeps the interval its configuration gives it: only pausing it is the screen's.
 */
final class UpdateLawWatchController
{
    public function __invoke(Request $request, string $watch): JsonResponse
    {
        $found = LawWatch::query()->findOrFail($watch);
        $changed = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'every_hours' => [$found->isDeclared() ? 'prohibited' : 'sometimes', 'integer', Rule::in(WatchSettings::read(config())->intervals)],
        ]);

        $found->fill($changed)->save();
        $viewer = $request->user()?->getAuthIdentifier();

        return response()->json(LawWatchView::of($found, is_scalar($viewer) ? (string) $viewer : null));
    }
}
