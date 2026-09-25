<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The same repair as `kern/platform`'s, for the class name this application used to have.
 *
 * `User` was `App\Domain\Identity\Models\User` here until it moved into the platform, and every role
 * assignment and stored notification written before that names it. The package cannot know that —
 * its own migration only knows the class name *it* has ever used — so the half that belongs to this
 * product's history lives here.
 *
 * The symptom was a 403 on every administrative screen, with the user's roles reading as an empty
 * list and nothing in any log.
 */
return new class () extends Migration {
    /** Where a user is referenced by type, and under which column. */
    private const array COLUMNS = [
        'model_has_roles' => 'model_type',
        'model_has_permissions' => 'model_type',
        'notifications' => 'notifiable_type',
    ];

    private const string BEFORE = 'App\\Domain\\Identity\\Models\\User';

    private const string ALIAS = 'user';

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where($column, self::BEFORE)->update([$column => self::ALIAS]);
            }
        }
    }

    /**
     * Deliberately empty.
     *
     * Reversing this would have to guess which of the two class names a row had, and the one this
     * migration replaces is a class that no longer exists in any version of this application.
     */
    public function down(): void
    {
        //
    }
};
