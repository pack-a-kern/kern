<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kern\Platform\Identity\Avatars\AvatarStore;
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
 * initials, and it is the one that should decide, not a fallback hidden behind an image URL.
 */
final class ShowUserAvatarController
{
    public function __invoke(Request $request, User $user, AvatarStore $store): SymfonyResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $isSelf = $viewer->getKey() === $user->getKey();

        abort_unless($isSelf || $viewer->can('users.view'), 403);

        $path = $user->avatar_path;

        abort_if($path === null, 404);

        $bytes = $store->read($path);

        /*
         * A row pointing at a file that is gone is a 404, not a 500.
         *
         * It is the state a restored database leaves behind when the disk was not restored with it, and
         * the honest answer is the same one as for an account that never had a picture.
         */
        abort_if($bytes === null, 404);

        return new Response($bytes, 200, [
            'Content-Type' => 'image/webp',
            /*
             * Cached hard, but only by the browser that asked.
             *
             * `private` keeps it out of any shared proxy, which matters because the response depends on
             * who is signed in. A year is safe because the URL carries `avatar_updated_at`: a new
             * picture is a new URL, so nothing has to expire for it to appear.
             */
            'Cache-Control' => 'private, max-age=31536000, immutable',
            // Nothing here is ever a document to download, and a browser that sniffs its way to another
            // conclusion about these bytes is a browser running something this installation served.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
