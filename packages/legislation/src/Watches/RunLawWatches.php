<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

use Illuminate\Support\Facades\DB;
use Kern\Legislation\Models\LawWatch;

/**
 * Every watch that is due, run (the hourly `legislation.watch` task) — each by `LawWatchRunner`, on its own
 * clock and under a lock of its own, failing alone: the others still run.
 *
 * The packages' watches are mirrored first from their declarations: one new is added, one changed takes
 * its new search, one no longer declared is removed.
 */
final readonly class RunLawWatches
{
    public function __construct(private LawWatchRunner $runner)
    {
    }

    /** @return list<string> a line per watch run, for the run's output */
    public function __invoke(WatchSettings $settings): array
    {
        $this->mirror($settings->declared);
        $lines = [];

        foreach (LawWatch::query()->where('enabled', true)->where('next_run_at', '<=', now())->orderBy('next_run_at')->cursor() as $watch) {
            $run = $this->runner->run($watch, $settings);

            if ($run !== null) {
                $lines[] = ($watch->key ?? $watch->id) . ': ' . $run->line();
            }
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
}
