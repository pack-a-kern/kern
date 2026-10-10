<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Avatars;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The bytes of an avatar, with the headers that make serving them safe.
 *
 * Shared by every controller that hands one out, because the headers are the interesting part and
 * there is no reason for two of them to disagree about it. What differs between those controllers is
 * who may look — that stays with each of them, where the rule can be read next to the thing it guards.
 */
final readonly class AvatarResponse
{
    public function __construct(private AvatarStore $store)
    {
    }

    /**
     * A 404 covers three cases on purpose: no picture was ever set, the row points at a file the disk
     * no longer has (what a database restored without its storage leaves behind), and the owner does
     * not exist. The screen draws initials for all three, which is the same answer.
     */
    public function of(HasAvatar $owner): SymfonyResponse
    {
        $path = $owner->avatarPath();

        abort_if($path === null, 404);

        $bytes = $this->store->read($path);

        abort_if($bytes === null, 404);

        return new Response($bytes, 200, [
            'Content-Type' => 'image/webp',
            /*
             * Cached hard, but only by the browser that asked.
             *
             * `private` keeps it out of any shared proxy, which matters because the response depends
             * on who is signed in. A year is safe because the URL carries `avatar_updated_at`: a new
             * picture is a new URL, so nothing has to expire for it to appear.
             */
            'Cache-Control' => 'private, max-age=31536000, immutable',
            // Nothing here is ever a document to download, and a browser that sniffs its way to
            // another conclusion about these bytes is a browser running something this installation
            // served.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
