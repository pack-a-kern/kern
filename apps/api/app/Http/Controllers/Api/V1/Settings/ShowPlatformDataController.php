<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Operations\DataTransferObjects\FreshnessItem;
use App\Domain\Operations\DataTransferObjects\SourceRegistryEntry;
use App\Domain\Operations\Services\ProbeDataFreshness;
use App\Domain\Operations\Services\ProbeSchedulerStatus;
use App\Domain\Operations\Services\ReadSourceAllowlist;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/settings/platform-data` — what this installation has ingested, and where from.
 *
 * Both halves belong with configuration rather than with the queue. The official-source allowlist is
 * `config/sources.php` mirrored into `ext_official_sources`, read-only by design — see
 * {@see ReadSourceAllowlist}. Data freshness is the other side of the same question: which of those
 * sources has actually produced something recently, and how stale it is allowed to get before the
 * screen says so (`config/operations.php`'s `freshness` block).
 *
 * One endpoint rather than three because the settings screen renders them together and they answer
 * one question between them: "BNR is allowed", "BNR last delivered nine days ago" and "the thing
 * that was supposed to ask BNR stopped beating four hours ago" are one answer split in three.
 *
 * Behind `system.settings.view`, not `operations.view`: reading what the platform is configured to
 * trust is a settings question, and the two permissions are held by different people.
 */
final class ShowPlatformDataController
{
    public function __invoke(
        ProbeDataFreshness $freshness,
        ReadSourceAllowlist $sources,
        ProbeSchedulerStatus $scheduler,
    ): JsonResponse {
        return response()->json([
            // The scheduler sits with the other service-reachability rows on the settings screen.
            // Read here rather than from `GET /v1/operations` because that endpoint is behind
            // `operations.view`, and the two permissions are held by different people.
            'scheduler' => $scheduler()->toArray(),
            'freshness' => array_map(
                static fn (FreshnessItem $item): array => $item->toArray(),
                $freshness(),
            ),
            'sources' => array_map(
                static fn (SourceRegistryEntry $entry): array => $entry->toArray(),
                $sources(),
            ),
        ]);
    }
}
