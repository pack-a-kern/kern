<?php

declare(strict_types=1);

namespace Kern\Notifications\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Kern\Notifications\Notice;
use Kern\Notifications\Services\NoticeTypes;
use Kern\Notifications\Services\Preferences;
use Kern\Notifications\Services\PushSender;

/**
 * A `Notice` on the person's devices — if, and only if, their switch for its kind is on — in the words
 * the bell shows, written in each device's language.
 */
final readonly class PushChannel
{
    public function __construct(
        private PushSender $sender,
        private Preferences $preferences,
        private NoticeTypes $types,
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof Model || ! $notification instanceof Notice) {
            return;
        }

        $key = $notification->key();

        if (! $this->preferences->allowsPush($notifiable, $key)) {
            return;
        }

        $id = (string) $notification->id;

        $this->sender->send(
            $notifiable,
            fn (string $locale) => $this->types->render($key, [$id => $notification->data], $notifiable, $locale)[$id] ?? null,
        );
    }
}
