<?php

declare(strict_types=1);

namespace Kern\Legislation\Dependents;

use Closure;

/**
 * What else in this installation needs a whole law to stay — not a paragraph of it, which is
 * `SectionDependents`' — a person's holding of a bond the law issued, for one.
 *
 * Whoever holds such a reference declares it with a name the screen can say ("holdings") and how to count
 * it, and `DeleteLaw` asks here, so this package names no table of another. What was read from a law and
 * is nobody's to keep is not declared here: it goes with the law, on `LawDeleting`.
 */
final class DocumentDependents
{
    /** @var array<string, Closure(string): int> */
    private array $declared = [];

    /**
     * "This many of these rest on a law, which must survive them" — from the declaring package's provider.
     *
     * @param  Closure(string): int  $count  how many rest on the law with this id
     */
    public function declare(string $name, Closure $count): void
    {
        $this->declared[$name] = $count;
    }

    /** @return array<string, int> how many of each declared kind rest on this law; none, empty */
    public function countFor(string $documentId): array
    {
        return array_filter(array_map(static fn (Closure $count): int => $count($documentId), $this->declared));
    }
}
