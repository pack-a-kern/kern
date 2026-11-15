<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

use Illuminate\Database\UniqueConstraintViolationException;
use Kern\Legislation\Models\LawWatch;

/**
 * Mirror one package-declared watch into `ext_law_watches`: the row {@see RunLawWatches} keeps in
 * step with the declaration, upserted on its own.
 *
 * Pulled out of {@see RunLawWatches::mirror()} so a single declared watch can be brought into being
 * without running every watch — the setup screen imports one programme's acts (its minimum-wage
 * decision, a bond order) by name, on a fresh install where nothing has been mirrored yet. The row
 * it writes is exactly the one the hourly run would, so the two never disagree.
 */
final readonly class MirrorDeclaredWatch
{
    public function __invoke(WatchDeclaration $declaration): LawWatch
    {
        // A select-then-save is not atomic: a second click, or the hourly run, can insert the same
        // `key` between the two and fail the unique constraint. On that one conflict the row now
        // exists, so a second pass finds and fills it instead of inserting again.
        try {
            return $this->upsert($declaration);
        } catch (UniqueConstraintViolationException) {
            return $this->upsert($declaration);
        }
    }

    private function upsert(WatchDeclaration $declaration): LawWatch
    {
        $watch = LawWatch::query()->firstOrNew(
            ['key' => $declaration->key],
            ['enabled' => true, 'next_run_at' => now()],
        );

        $watch->fill([
            'declared_by' => $declaration->declaredBy,
            'search_words' => $declaration->search,
            'act_type' => $declaration->actType,
            'title_pattern' => $declaration->titlePattern,
            'first_year' => $declaration->firstYear,
            'every_hours' => $declaration->everyHours,
        ])->save();

        return $watch;
    }
}
