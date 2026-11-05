<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Notifications\Contracts\SendsPush;
use Kern\Platform\Notifications\Services\NotificationPreferences;
use Kern\Platform\Notifications\Services\PushSender;

/**
 * The device notification channel: a notification that `SendsPush` names this class in its `via()`, and
 * reaches the person's devices if — and only if — their switch for that kind is on.
 */
final readonly class WebPushChannel
{
    public function __construct(
        private PushSender $sender,
        private NotificationPreferences $preferences,
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof SendsPush) {
            return;
        }

        if (! $this->preferences->allowsPush($notifiable, $notification->pushType())) {
            return;
        }

        $this->sender->toUser($notifiable, static fn (string $locale) => $notification->toPush($notifiable, $locale));
    }
}
