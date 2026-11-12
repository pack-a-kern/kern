<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Kern\Legislation\Models\LawWatch;

/**
 * A watch as the laws screen shows it: what it searches, how often, what its last run did, whether a run is
 * on its way, how many laws it read in, and whose it is.
 */
final class LawWatchView
{
    /** @return array<string, mixed> */
    public static function of(LawWatch $watch, ?string $viewerId): array
    {
        return [
            'id' => $watch->id,
            'declaredBy' => $watch->declared_by,
            'mine' => $watch->created_by !== null && $watch->created_by === $viewerId,
            'searchWords' => $watch->search_words,
            'actType' => $watch->act_type,
            'firstYear' => $watch->first_year,
            'everyHours' => $watch->every_hours,
            'enabled' => $watch->enabled,
            'nextRunAt' => $watch->next_run_at->toIso8601String(),
            'lastRunAt' => $watch->last_run_at?->toIso8601String(),
            'lastSummary' => $watch->last_summary,
            'running' => $watch->isRunning(),
            // Counted with the list; a watch shown on its own counts them here.
            'laws' => $watch->laws_count ?? $watch->laws()->count(),
        ];
    }
}
