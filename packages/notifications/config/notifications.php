<?php

declare(strict_types=1);

return [
    /*
     * Where the endpoints hang (`routes/api.php`): the bell, the person's choices and their devices.
     * Every one of them acts for the signed-in person only, so the middleware must authenticate.
     */
    'routes' => [
        'prefix' => env('NOTIFICATIONS_ROUTE_PREFIX', 'api/v1/notifications'),
        'middleware' => ['web', 'auth'],
    ],

    // How many unread notices the bell lists at most. A menu, not an inbox: read ones are not shown.
    'bell_limit' => (int) env('NOTIFICATIONS_BELL_LIMIT', 20),

    'push' => [
        /*
         * The server's identity towards the push services (VAPID, RFC 8292): a key pair whose public
         * half every browser is given when it subscribes, and whose private half signs each send.
         * Empty means device notifications are off, and the preferences endpoint says so.
         * `php artisan notifications:keys` makes a pair.
         */
        'vapid' => [
            'public_key' => env('WEB_PUSH_PUBLIC_KEY', ''),
            'private_key' => env('WEB_PUSH_PRIVATE_KEY', ''),
            // Who the push services may contact about this server's sends: a mailto: or an https:
            // address. Empty is APP_URL.
            'subject' => env('WEB_PUSH_SUBJECT', ''),
        ],

        /*
         * The only hosts a device's push address may be on — the browsers' own push services. A
         * device hands the server an address and the server then sends to it, so without this list a
         * subscription would be a way to make the server call anything, its own network included.
         * A host matches itself and its subdomains.
         */
        'hosts' => [
            'fcm.googleapis.com',          // Chrome, Edge, Android
            'push.apple.com',              // Safari, iOS and iPadOS web apps
            'push.services.mozilla.com',   // Firefox
            'notify.windows.com',          // Edge on Windows
        ],

        // How long a push service keeps a notification for a device that is off, in seconds.
        'ttl_seconds' => (int) env('WEB_PUSH_TTL_SECONDS', 86400),

        // Devices one person may have notifications on at once.
        'max_devices' => (int) env('WEB_PUSH_MAX_DEVICES', 10),
    ],

    'rate_limits' => [
        // Turning notifications on or off for a device.
        'devices' => ['max_attempts' => 10, 'decay_seconds' => 300],
        // "Send a test": each one is a request to a push service on the person's behalf.
        'test' => ['max_attempts' => 3, 'decay_seconds' => 60],
    ],
];
