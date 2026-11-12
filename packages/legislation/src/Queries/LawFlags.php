<?php

declare(strict_types=1);

namespace Kern\Legislation\Queries;

use Closure;

/**
 * What another package has to say beside a law on the laws screen, as a code the screen words and a count
 * — "2 bonds skipped: already in another order", for one. Declared by that package, so this one names no
 * table of another; asked once for every law listed.
 */
final class LawFlags
{
    /** @var array<string, Closure(list<string>): array<string, int>> */
    private array $declared = [];

    /**
     * @param  Closure(list<string>): array<string, int>  $count  by law id, for these law ids; a law with
     *                                                             nothing to say is left out, or counts 0
     */
    public function declare(string $code, Closure $count): void
    {
        $this->declared[$code] = $count;
    }

    /**
     * @param  list<string>  $lawIds
     * @return array<string, list<array{code: string, count: int}>> by law id; a law with nothing to say, left out
     */
    public function for(array $lawIds): array
    {
        $flags = [];

        foreach ($this->declared as $code => $count) {
            foreach (array_filter($count($lawIds)) as $lawId => $counted) {
                $flags[$lawId][] = ['code' => $code, 'count' => $counted];
            }
        }

        return $flags;
    }
}
