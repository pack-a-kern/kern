<?php

declare(strict_types=1);

namespace Kern\Legislation\Dependents;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What else in this installation rests on a paragraph of the law.
 *
 * A law may not be deleted while anything points at its text. This package cannot know what does:
 * here it is an approved fiscal value and a tax rule's citation, elsewhere it would be a clause in a
 * contract template or nothing at all. So whoever holds such a reference declares it, and `DeleteLaw`
 * asks here.
 *
 * `DeleteLaw` used to count `fiscal_parameter_versions` and `tax_rule_sources` by name, which meant
 * installing this package without the Romanian tax one left it counting rows in tables that did not
 * exist. Found by doing that.
 */
final class SectionDependents
{
    /** @var list<array{table: string, column: string}> */
    private array $declared = [];

    /**
     * "This table's rows point at a section, and the law that section belongs to must survive them."
     *
     * Called from the declaring package's service provider. Declaring the same table twice is
     * harmless and counted once.
     */
    public function declare(string $table, string $column): void
    {
        foreach ($this->declared as $existing) {
            if ($existing === ['table' => $table, 'column' => $column]) {
                return;
            }
        }

        $this->declared[] = ['table' => $table, 'column' => $column];
    }

    /** How many rows, across everything declared, rest on any of these sections. */
    public function countFor(Builder $sectionIds): int
    {
        $total = 0;

        foreach ($this->declared as $dependent) {
            $total += DB::table($dependent['table'])
                ->whereIn($dependent['column'], $sectionIds->clone())
                ->count();
        }

        return $total;
    }
}
