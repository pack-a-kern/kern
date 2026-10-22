<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Avatars;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Pulls the bytes of an avatar out of a request, or refuses before anything is decoded.
 *
 * The cheap half of accepting an upload: a file arrived, and it is not absurdly large. The expensive
 * half — scanning it, and rebuilding it so the stored image shares nothing with what was sent — is
 * {@see SetAvatar}'s, and runs after this.
 *
 * Here rather than in each controller because there are two of them now (a person's own picture and
 * a taxpayer's) and the checks must not drift: the moment one of them forgets the size ceiling, the
 * limit is only enforced by whichever route the attacker did not pick.
 *
 * Deliberately **not** Laravel's `image` rule, which trusts the MIME type and the extension — both
 * supplied by whoever is uploading. What the file actually is gets decided from its bytes, later,
 * where refusing it has cost nothing that was written.
 *
 * `max_bytes` is therefore checked twice, and that is intended: once here, so an oversized upload is
 * refused with a validation error naming the limit, and once inside the action, so the limit holds
 * for every caller rather than only for one that came through a controller.
 */
final readonly class AvatarUpload
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /** @return string the uploaded file's raw contents */
    public function bytesFrom(Request $request): string
    {
        $maxKilobytes = intdiv($this->config->integer('avatars.max_bytes'), 1024);

        $request->validate([
            'avatar' => ['required', 'file', "max:{$maxKilobytes}"],
        ]);

        $file = $request->file('avatar');

        if (! $file instanceof UploadedFile) {
            abort(422, __('platform::account.avatar.missing'));
        }

        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false) {
            abort(422, __('platform::account.avatar.unreadable'));
        }

        return $bytes;
    }
}
