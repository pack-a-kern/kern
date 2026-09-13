<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Actions\EraseUser;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/admin/users/{user}` — erases a person from this installation.
 *
 * Super administrators only, and never oneself: an administrator who erases their own account
 * destroys the credentials of the only role that can undo it.
 *
 * What "erase" can and cannot mean here is explained in `App\Domain\Identity\Actions\EraseUser` —
 * in short, everything about the person goes, and the audit trail of what they did stays, because
 * a chain that can be edited is not a chain.
 */
final class EraseUserController extends Controller
{
    public function __invoke(Request $request, string $user, EraseUser $erase): JsonResponse
    {
        $administrator = $request->user();
        abort_unless($administrator !== null, 401);
        abort_unless($administrator->hasRole('SUPER_ADMIN'), 403);

        $target = User::query()->findOrFail($user);
        abort_if($target->id === $administrator->id, 422, 'You cannot erase your own account.');
        abort_if($target->hasRole('SUPER_ADMIN'), 403, 'A super administrator cannot be erased.');
        // Already a tombstone; erasing it again would write a second event about nothing.
        abort_if($target->status === UserStatus::DELETED, 422, 'This account has already been erased.');

        $erase($target, $administrator->id);

        return response()->json(['id' => $target->id, 'status' => 'DELETED']);
    }
}
