<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers;

use Illuminate\Http\Request;
use Kern\Platform\Identity\Avatars\AvatarResponse;
use Kern\Platform\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET /api/v1/users/{user}/avatar` — the picture, to anybody entitled to see that this account exists.
 *
 * ## Why this is a route and not a public file
 *
 * The obvious implementation is the `public` disk and a symlink, and it is what most applications do.
 * It would put every user's face behind a URL with no middleware in front of it — guessable, shareable,
 * and readable by anybody who ever learns it, including after the account is suspended. These are the
 * faces of the people who do this installation's accounting. They are worth a controller.
 *
 * ## Who may look
 *
 * Your own, always. Anybody else's requires `users.view` — the permission that already governs seeing
 * the list of accounts. Without it, an authenticated person could walk the id space and learn both who
 * exists and what they look like.
 *
 * A missing avatar is a 404 rather than a placeholder image: the screen already knows how to draw
 * initials, and it is the one that should decide, not a fallback hidden behind an image URL. The
 * bytes and the headers are {@see AvatarResponse}'s, shared with the taxpayer's picture; who may look
 * is this controller's, and stays here where it can be read beside what it guards.
 */
final class ShowUserAvatarController
{
    public function __invoke(Request $request, User $user, AvatarResponse $avatar): SymfonyResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $isSelf = $viewer->getKey() === $user->getKey();

        abort_unless($isSelf || $viewer->can('users.view'), 403);

        return $avatar->of($user);
    }
}
