<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Kern\Legislation\Enums\PortalSearchFailure;
use Kern\Legislation\Legislatie\PortalAct;
use Kern\Legislation\Legislatie\PortalQuery;
use Kern\Legislation\Legislatie\PortalSearch;
use Kern\Legislation\Models\IgnoredAct;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Models\SourceDocument;
use RuntimeException;
use Throwable;

/**
 * One run of one watch, under a lock of its own — the hourly task's, and a person's "Run now": its search
 * made the way the settings say, as the "Add a law" dialog makes it, and each new act it finds read as a
 * law, kept as found by this watch. An act already a law, or one a person asked the watches to ignore, is
 * passed over. What it did is kept on the watch as counts; a run that did not finish — failed, or left
 * acts for later — is tried again within the hour, and is not the clean run `last_run_at` records.
 */
final readonly class LawWatchRunner
{
    public function __construct(
        private PortalSearch $search,
        private SyncLegislationDocument $sync,
        private Cache $cache,
    ) {
    }

    /**
     * @param  bool  $wait  whether to wait for another run of this watch to end — a person asked for this
     *                      one — rather than leave it to that run
     * @return WatchRun|null what it did; null when another run of this watch holds it and it did not wait
     */
    public function run(LawWatch $watch, WatchSettings $settings, bool $wait = false): ?WatchRun
    {
        // The cache *store* locks, not the repository; one that cannot is refused rather than left to let
        // two runs of a watch race.
        $locks = $this->cache->store()->getStore();

        if (! $locks instanceof LockProvider) {
            throw new RuntimeException('The configured cache store provides no atomic locks.');
        }

        $lock = $locks->lock("legislation.watch.{$watch->id}", $settings->lockSeconds);

        if (! ($wait ? $lock->block($settings->lockSeconds) : $lock->get())) {
            return null;
        }

        $years = $this->years($watch, $settings);

        // What failed is said as a code a screen can show; the exception itself goes to the log, where its
        // addresses and internals belong.
        try {
            $run = $this->search($watch, $settings, $years);
        } catch (Throwable $exception) {
            report($exception);
            $run = WatchRun::stopped($years, PortalSearchFailure::of($exception));
        } finally {
            $lock->release();
        }

        $now = now();
        $watch->forceFill([
            'last_run_at' => $run->finished() ? $now : $watch->last_run_at,
            'next_run_at' => $now->copy()->addHours($run->finished() ? $watch->every_hours : 1),
            'last_summary' => $run->toArray(),
            'run_requested_at' => null,
        ])->save();

        return $run;
    }

    /**
     * Its first run every year from its first; each later one this year and, early in the year, the last.
     *
     * @return list<int>
     */
    private function years(LawWatch $watch, WatchSettings $settings): array
    {
        $today = Carbon::today();

        return $watch->last_run_at === null
            ? range($watch->first_year, $today->year)
            : ($today->dayOfYear <= $settings->overlapDays ? [$today->year - 1, $today->year] : [$today->year]);
    }

    /** @param  list<int>  $years */
    private function search(LawWatch $watch, WatchSettings $settings, array $years): WatchRun
    {
        $found = $started = $known = $ignored = $waiting = $failed = $retries = 0;

        foreach ($years as $year) {
            $findings = $this->search->collect(PortalQuery::byTitle($watch->search_words, $year, $watch->act_type));
            $acts = array_values(array_filter($findings->acts, static fn (PortalAct $act): bool => $watch->keeps($act->title)));
            $retries += $findings->retries;
            $found += count($acts);
            $ids = array_values(array_filter(array_map(static fn (PortalAct $act): ?string => $act->portalId(), $acts)));
            /** @var list<string> $readIds */
            $readIds = SourceDocument::query()->whereIn('portal_document_id', $ids)->pluck('portal_document_id')->all();
            /** @var list<string> $ignoredIds */
            $ignoredIds = IgnoredAct::query()->whereIn('portal_document_id', $ids)->pluck('portal_document_id')->all();
            $read = array_flip($readIds);
            $passedOver = array_flip($ignoredIds);

            foreach ($acts as $act) {
                $id = $act->portalId() ?? '';

                if (isset($read[$id])) {
                    $known++;

                    continue;
                }

                if (isset($passedOver[$id])) {
                    $ignored++;

                    continue;
                }

                // A search too broad reads no more than its share a run; the rest wait for the next.
                if ($started + $failed >= $settings->maxNewActs) {
                    $waiting++;

                    continue;
                }

                // One act that will not start is that act's failure, not the watch's.
                try {
                    ($this->sync)($watch->act_type, (int) $act->number, $act->year() ?? $year, null, $act->portalId(), $watch->id);
                    $started++;
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            }
        }

        return new WatchRun($years, $found, $started, $known, $ignored, $waiting, $failed, $retries, null);
    }
}
