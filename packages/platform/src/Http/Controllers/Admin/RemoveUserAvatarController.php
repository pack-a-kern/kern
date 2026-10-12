<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Avatars\RemoveAvatar;
use Kern\Platform\Identity\Models\User;

/**
 * `DELETE /api/v1/admin/users/{user}/avatar` — takes a picture off somebody else's account.
 *
 * Removal only, and the asymmetry is the point. Taking down a picture that should not be there is a
 * real administrative duty; choosing the face somebody else wears is not one, so uploading moved to
 * {@see \Kern\Platform\Http\Controllers\Account\UpdateOwnAvatarController}, where the only
 * account reachable is the caller's own.
 *
 * Behind `permission:users.manage` with the rest of the administrative account routes.
 */
final class RemoveUserAvatarController
{
    public function __invoke(Request $request, User $user, RemoveAvatar $remove): JsonResponse
    {
        $actorKey = $request->user()?->getKey();

        $remove($user, is_scalar($actorKey) ? (string) $actorKey : null);

        return response()->json(['avatar_updated_at' => null]);
    }
}
