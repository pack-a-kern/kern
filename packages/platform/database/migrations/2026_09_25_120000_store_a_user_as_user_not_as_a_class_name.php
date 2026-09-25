<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kern\Platform\Identity\Models\User;

/**
 * Every polymorphic reference to a user becomes `user`, the alias, instead of a class name.
 *
 * `PlatformServiceProvider` registers that alias now; this rewrites the rows written before it did.
 * Both tables store whatever `getMorphClass()` returned at the time — the fully qualified class name
 * — so a class that moves package takes every one of those rows out of service: a role assignment
 * that names a class PHP can no longer load matches nothing, and the person holds no roles at all.
 * That is not a degraded state you notice; it is a 403 on every administrative screen with no error
 * anywhere, which is exactly how it was found.
 *
 * Idempotent, and it names the old value as well as the current one, because an installation may be
 * at either point in that history. A fresh one has no rows to rewrite.
 */
return new class () extends Migration {
    /** Where a user is referenced by type, and under which column. */
    private const array COLUMNS = [
        'model_has_roles' => 'model_type',
        'model_has_permissions' => 'model_type',
        'notifications' => 'notifiable_type',
    ];

    private const string ALIAS = 'user';

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->where($column, User::class)->update([$column => self::ALIAS]);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->where($column, self::ALIAS)->update([$column => User::class]);
        }
    }
};
