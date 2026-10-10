<?php

declare(strict_types=1);

/**
 * Profile pictures. Nothing here is a tax document: an avatar is a face somebody chose to show other
 * people in the same installation, so it is stored on an ordinary disk rather than in the encrypted
 * object store, and it is never written to the quarantine bucket.
 *
 * It is still served through an authenticated route and never from a public URL — this installation
 * knows who its users are, and their faces are not public information.
 */
return [

    /*
     * The disk avatars live on. `avatars` by default, a local disk under storage/app/private —
     * deliberately not `public`, which would need storage:link and would put every face behind a
     * guessable URL that no middleware sees.
     */
    'disk' => env('AVATAR_DISK', 'avatars'),

    /*
     * The largest upload accepted, in bytes, measured before anything is decoded.
     *
     * 4 MB takes any photograph a phone produces. It is not the memory guard — a 4 MB PNG can still
     * declare enormous dimensions, which is what `max_side` is for.
     */
    'max_bytes' => (int) env('AVATAR_MAX_BYTES', 4 * 1024 * 1024),

    /*
     * The largest input accepted per side, in pixels, checked from the header before the decode.
     *
     * A decoded image costs roughly width x height x 4 bytes whatever the file weighed, so this is the
     * number that bounds memory: 8000 allows about 256 MB in the worst case, which is survivable, and
     * refuses the 40000x40000 PNG that would ask for six gigabytes.
     */
    'max_side' => (int) env('AVATAR_MAX_SIDE', 8000),

    /*
     * What gets stored: a square of this many pixels, in WebP.
     *
     * 256 serves every place an avatar appears — the largest is 96px in a dialog — with room for a
     * 2x display. WebP because it carries both photographs and transparency, which JPEG and PNG each
     * do only one of; at this size and quality a face is 8-15 KB.
     */
    'side' => (int) env('AVATAR_SIDE', 256),
    'quality' => (int) env('AVATAR_QUALITY', 82),

];
