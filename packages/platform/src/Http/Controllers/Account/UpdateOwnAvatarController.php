<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Account;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Avatars\AvatarUpload;
use Kern\Platform\Identity\Avatars\RemoveAvatar;
use Kern\Platform\Identity\Avatars\SetAvatar;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;

/**
 * `POST /api/v1/me/avatar` — the signed-in person gives their own account a picture.
 * `DELETE /api/v1/me/avatar` — and takes it away again.
 *
 * ## Why this needs no permission
 *
 * There is no id in the route. The only account it can reach is `$request->user()`, so the identity
 * *is* the authorization and there is nothing to tamper with — which is exactly why choosing your own
 * face stopped being an administrator's job. The administrative route that remains can only *remove*
 * a picture ({@see \Kern\Platform\Http\Controllers\Admin\RemoveUserAvatarController}), because taking
 * down something inappropriate is a real duty and picking somebody else's face is not.
 *
 * Everything that makes an upload safe is elsewhere and shared: {@see AvatarUpload} weighs it before
 * anything decodes it, {@see SetAvatar} scans the bytes as they arrived and rebuilds the image so the
 * stored file shares none of them. This controller only says whose picture it is.
 */
final class UpdateOwnAvatarController
{
    public function store(Request $request, SetAvatar $set, AvatarUpload $upload): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        $bytes = $upload->bytesFrom($request);

        try {
            // Actor and owner are the same person here, which is the whole point of the route; the
            // audit row still records both, because the action does not know that in general.
            $set($user, $bytes, $user->avatarOwnerId());
        } catch (UploadRejectedException $e) {
            // 422 for every refusal, a scanner outage included: a 503 reads to a browser as "try
            // again shortly", and a scanner that is down is not something the uploader can wait out.
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'avatar_updated_at' => $user->avatar_updated_at?->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, RemoveAvatar $remove): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        $remove($user, $user->avatarOwnerId());

        return response()->json(['avatar_updated_at' => null]);
    }
}
