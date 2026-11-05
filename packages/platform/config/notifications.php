<?php

declare(strict_types=1);

return [
    'push' => [
        /*
         * The server's identity towards the push services (VAPID, RFC 8292): a key pair whose public
         * half every browser is given when it subscribes, and whose private half signs each send.
         * Empty means device notifications are off — the account page says so instead of offering
         * them. `php artisan kern:push:keys` makes a pair; the private key never leaves this file.
         */
        'vapid' => [
            'public_key' => env('WEB_PUSH_PUBLIC_KEY', ''),
            'private_key' => env('WEB_PUSH_PRIVATE_KEY', ''),
            // Who to reach about this server's sends, as the push services ask: a mailto: or an
            // https: address. Empty is this installation's own address (APP_URL).
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

        // Devices one account may have notifications on at once.
        'max_devices_per_user' => (int) env('WEB_PUSH_MAX_DEVICES_PER_USER', 10),
    ],

    'rate_limits' => [
        // Turning notifications on or off for a device.
        'push_devices_per_user' => ['max_attempts' => 10, 'decay_seconds' => 300],
        // "Send a test": each is a request to a push service on this account's behalf.
        'push_test_per_user' => ['max_attempts' => 3, 'decay_seconds' => 60],
    ],
];
