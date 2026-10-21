<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;

/**
 * Takes the platform roles and permissions off every account erased before `EraseUser` did so itself.
 *
 * Erasure removed an account's sign-ins, second factors and memberships and kept its roles. A tombstone
 * cannot sign in, but one that still held `SUPER_ADMIN` was counted as a super administrator, so the guard
 * on removing the last one counted it too — and would not have warned before the real last one went.
 * `EraseUser` now removes them as it erases; this is the same for the accounts erased before it did.
 *
 * Rows only, on both databases, and no cache to clear: the permission cache holds what each role may do,
 * not who holds it. Nothing to put back on the way down: the roles were never meant to be there.
 */
return new class () extends Migration {
    public function up(): void
    {
        $erased = DB::table('users')->where('status', UserStatus::DELETED->value)->select('id');
        $type = (new User())->getMorphClass();

        // The tables by name, as `create_permission_tables` names them: a config edit must not point
        // this at tables that are not the ones that exist.
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table($table)->where('model_type', $type)->whereIn('model_id', $erased)->delete();
        }
    }

    public function down(): void
    {
    }
};
