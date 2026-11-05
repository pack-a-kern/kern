<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\Notifications;

use Illuminate\Notifications\Notification;
use Kern\Platform\Notifications\DataTransferObjects\PushMessage;

/**
 * "Send a test" on the account page: one notice to every device of the account, so a person can see
 * that notifications arrive. Not a kind in the catalogue — it is asked for, not subscribed to — and not
 * kept in the bell.
 */
final class TestPushNotification extends Notification
{
    /** What it says on a device in `$locale`. */
    public function toPush(string $locale): PushMessage
    {
        return new PushMessage(
            title: __('platform::notifications.push.test_title', [], $locale),
            body: __('platform::notifications.push.test_body', [], $locale),
            url: '/account',
            tag: 'kern-test',
        );
    }
}
