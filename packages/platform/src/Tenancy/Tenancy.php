<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this installation calls its tenant, for the code that has to write the word down.
 *
 * {@see ScopedToTenant} covers the reads and the writes that go through Eloquent, but not every
 * line can be implicit: a query builder needs a column name, an array passed to `create()` needs a
 * key, and a migration needs to declare the column in the first place. Every one of those asks here
 * instead of spelling `tax_subject_id`, which is the difference between a package that can be
 * installed beside a workspace and one that can only ever serve taxpayers.
 */
final class Tenancy
{
    /** The column every tenant-scoped table carries. */
    public static function column(): string
    {
        return config()->string('tenancy.column');
    }

    /**
     * The table that column points at, or null where this installation has no tenants.
     *
     * Null is a real answer, not a missing one: identity, encryption, the audit chain and documents
     * all work with nothing to scope to.
     */
    public static function table(): ?string
    {
        $table = config()->get('tenancy.table');

        return is_string($table) && $table !== '' ? $table : null;
    }

    /**
     * Declare the tenant column on a table being created.
     *
     * The foreign key is added only when the owner's table is already there, which is what lets a
     * package be installed without the one that owns the tenant: this installation gets today's
     * `cascadeOnDelete`, and an installation with no tenants gets an indexed column and no
     * constraint. A column with no constraint everywhere would have been the cheaper answer and the
     * wrong one — it loses the referential integrity this product does rely on.
     */
    public static function addColumn(Blueprint $table, bool $primary = false): void
    {
        $column = self::column();

        $definition = $table->uuid($column);

        if ($primary) {
            $definition->primary();
        }

        $owner = self::table();

        if ($owner !== null && Schema::hasTable($owner)) {
            $table->foreign($column)->references('id')->on($owner)->cascadeOnDelete();
        }
    }
}
