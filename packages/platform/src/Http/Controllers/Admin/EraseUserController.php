<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Access\SuperAdministratorRole;
use Kern\Platform\Identity\Actions\EraseUser;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;

/**
 * `DELETE /api/v1/admin/users/{user}` — erases a person from this installation.
 *
 * Super administrators only, and never oneself: an administrator who erases their own account
 * destroys the credentials of the only role that can undo it.
 *
 * What "erase" can and cannot mean here is explained in `Kern\Platform\Identity\Actions\EraseUser` —
 * in short, everything about the person goes, and the audit trail of what they did stays, because
 * a chain that can be edited is not a chain.
 */
final class EraseUserController
{
    public function __invoke(Request $request, string $user, EraseUser $erase): JsonResponse
    {
        $administrator = $request->user();
        abort_unless($administrator !== null, 401);
        abort_unless(SuperAdministratorRole::isHeldBy($administrator), 403);

        $target = User::query()->findOrFail($user);
        abort_if($target->id === $administrator->id, 422, __('platform::admin.erase.self'));
        abort_if(SuperAdministratorRole::isHeldBy($target), 403, __('platform::admin.erase.super_administrator'));
        // Already a tombstone; erasing it again would write a second event about nothing.
        abort_if($target->status === UserStatus::DELETED, 422, __('platform::admin.erase.already_erased'));

        $erase($target, $administrator->id);

        return response()->json(['id' => $target->id, 'status' => 'DELETED']);
    }
}
