<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Kern\Legislation\Legislatie\PortalAct;
use Kern\Legislation\Legislatie\PortalQuery;
use Kern\Legislation\Legislatie\PortalSearch;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Models\SourceDocument;
use Throwable;

/**
 * Every watch that is due, run (the hourly `legislation.watch` task): its search made the way the
 * settings say, as the "Add a law" dialog makes it, and each new act it finds read as a law — whoever
 * watches for it reads what it needs from it once read, through `LawConsolidationsRead`.
 *
 * The packages' watches are mirrored first from their declarations: one new is added, one changed takes
 * its new search, one no longer declared is removed. Each watch then runs on its own clock — its first
 * run every year from its first, each later run this year and, early in the year, the last — under a
 * lock of its own, and fails alone: its reason is its last result, it is tried again within the hour,
 * and the others still run.
 */
final readonly class RunLawWatches
{
    public function __construct(
        private PortalSearch $search,
        private SyncLegislationDocument $sync,
        private Cache $cache,
    ) {
    }

    /** @return list<string> a line per watch run, for the run's output */
    public function __invoke(WatchSettings $settings): array
    {
        $this->mirror($settings->declared);

        $lines = [];

        foreach (LawWatch::query()->where('enabled', true)->where('next_run_at', '<=', now())->orderBy('next_run_at')->cursor() as $watch) {
            $lock = $this->cache->lock("legislation.watch.{$watch->id}", $settings->lockSeconds);

            if (! $lock->get()) {
                continue;
            }

            try {
                $result = $this->run($watch, $settings);
            } catch (Throwable $exception) {
                $result = "FAILED: {$exception->getMessage()}";
            } finally {
                $lock->release();
            }

            // A run that failed, all or in part, is tried again at the next hourly run, and is not the clean
            // run `last_run_at` records — so a first run that failed still searches every year next time.
            $now = now();
            $failed = str_contains($result, 'FAILED');
            $watch->forceFill([
                'last_run_at' => $failed ? $watch->last_run_at : $now,
                'next_run_at' => $now->copy()->addHours($failed ? 1 : $watch->every_hours),
                'last_result' => mb_substr($result, 0, 500),
            ])->save();
            $lines[] = ($watch->key ?? $watch->id) . ": {$result}";
        }

        return $lines;
    }

    /** @param list<WatchDeclaration> $declared */
    private function mirror(array $declared): void
    {
        DB::transaction(static function () use ($declared): void {
            foreach ($declared as $declaration) {
                $watch = LawWatch::query()->firstOrNew(['key' => $declaration->key], ['enabled' => true, 'next_run_at' => now()]);
                $watch->fill([
                    'declared_by' => $declaration->declaredBy,
                    'search_words' => $declaration->search,
                    'act_type' => $declaration->actType,
                    'title_pattern' => $declaration->titlePattern,
                    'first_year' => $declaration->firstYear,
                    'every_hours' => $declaration->everyHours,
                ])->save();
            }

            LawWatch::query()->whereNotNull('key')->whereNotIn('key', array_map(static fn (WatchDeclaration $d): string => $d->key, $declared))->delete();
        });
    }

    private function run(LawWatch $watch, WatchSettings $settings): string
    {
        $today = Carbon::today();
        $years = $watch->last_run_at === null
            ? range($watch->first_year, $today->year)
            : ($today->dayOfYear <= $settings->overlapDays ? [$today->year - 1, $today->year] : [$today->year]);
        $found = 0;
        $started = 0;
        $failed = [];

        foreach ($years as $year) {
            $acts = array_filter($this->search->all(PortalQuery::byTitle($watch->search_words, $year, $watch->act_type)), static fn (PortalAct $act): bool => $watch->keeps($act->title));
            $found += count($acts);

            foreach ($acts as $act) {
                if (SourceDocument::query()->where('portal_document_id', $act->portalId())->exists()) {
                    continue;
                }

                // One act that will not start is that act's failure, not the watch's.
                try {
                    ($this->sync)($watch->act_type, (int) $act->number, $act->year() ?? $year, null, $act->portalId());
                    $started++;
                } catch (Throwable $exception) {
                    $failed[] = "{$act->number}/{$year} ({$exception->getMessage()})";
                }
            }
        }

        return sprintf('%d acts found in %s, %d new read as laws', $found, implode(', ', $years), $started)
            . ($failed === [] ? '' : '; FAILED to start ' . implode('; ', $failed));
    }
}
