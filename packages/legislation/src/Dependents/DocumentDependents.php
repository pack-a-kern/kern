<?php

declare(strict_types=1);

namespace Kern\Legislation\Dependents;

use Illuminate\Support\Facades\DB;

/**
 * What else in this installation was read from a whole law — not a paragraph of it, which is
 * `SectionDependents`' — and needs the law to stay: a government bond whose terms were read from the
 * order that issued it, for one.
 *
 * Whoever holds such a reference declares it with a name the screen can say ("instruments"), and
 * `DeleteLaw` asks here, so this package names no table of another.
 */
final class DocumentDependents
{
    /** @var array<string, array{table: string, column: string}> */
    private array $declared = [];

    /** "These rows were read from a law, which must survive them" — from the declaring package's provider. */
    public function declare(string $name, string $table, string $column): void
    {
        $this->declared[$name] = ['table' => $table, 'column' => $column];
    }

    /** @return array<string, int> how many rows of each declared kind rest on this law; none, empty */
    public function countFor(string $documentId): array
    {
        $counts = array_map(
            static fn (array $dependent): int => DB::table($dependent['table'])->where($dependent['column'], $documentId)->count(),
            $this->declared,
        );

        return array_filter($counts);
    }
}
