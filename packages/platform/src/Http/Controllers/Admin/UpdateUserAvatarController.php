<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Admin;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Kern\Platform\Identity\Avatars\RemoveUserAvatar;
use Kern\Platform\Identity\Avatars\SetUserAvatar;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;

/**
 * `POST /api/v1/admin/users/{user}/avatar` — gives an account a picture.
 * `DELETE /api/v1/admin/users/{user}/avatar` — takes it away again.
 *
 * The validation here is the cheap half: a file arrived, and it is not absurdly large. It deliberately
 * does not use Laravel's `image` rule, which trusts the client's MIME type and the extension — both
 * supplied by whoever is uploading. What the file actually is gets decided from its bytes, inside
 * {@see SetUserAvatar}, where refusing it costs nothing that has been written.
 *
 * `max_bytes` is checked twice, and that is intended: once here so an oversized upload is refused with a
 * validation error naming the limit, and once in the action so the limit holds for every caller, not
 * only for one that happened to come through this route.
 */
final class UpdateUserAvatarController
{
    public function store(
        Request $request,
        User $user,
        SetUserAvatar $set,
        ConfigRepository $config,
    ): JsonResponse {
        $maxKilobytes = intdiv($config->integer('avatars.max_bytes'), 1024);

        $request->validate([
            'avatar' => ['required', 'file', "max:{$maxKilobytes}"],
        ]);

        $file = $request->file('avatar');

        if (! $file instanceof UploadedFile) {
            abort(422, 'No file was uploaded.');
        }

        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false) {
            abort(422, 'The upload could not be read.');
        }

        $actorKey = $request->user()?->getKey();

        try {
            $user = $set($user, $bytes, is_scalar($actorKey) ? (string) $actorKey : null);
        } catch (UploadRejectedException $e) {
            /*
             * 422 for every refusal, including an unavailable scanner.
             *
             * A 503 there would be more literally true, but it reads to a browser — and to any retry
             * logic in front of it — as "try again shortly", and a scanner that is down is not a thing
             * the person uploading can wait out. The `reason` says which refusal it was, and the screen
             * translates it into a sentence that tells them what to do.
             */
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'avatar_updated_at' => $user->avatar_updated_at?->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, User $user, RemoveUserAvatar $remove): JsonResponse
    {
        $actorKey = $request->user()?->getKey();

        $remove($user, is_scalar($actorKey) ? (string) $actorKey : null);

        return response()->json(['avatar_updated_at' => null]);
    }
}
