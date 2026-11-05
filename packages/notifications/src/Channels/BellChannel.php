<?php

declare(strict_types=1);

namespace Kern\Notifications\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Kern\Notifications\Models\StoredNotice;
use Kern\Notifications\Notice;

/**
 * Keeps a `Notice` for the bell: its kind and its `data`, nothing else.
 *
 * Laravel's `database` channel would write through the person's `notifications()` relation, in whole
 * seconds and only for a model with the `Notifiable` trait; this writes the row itself, to the
 * microsecond (`StoredNotice`), for any model.
 */
final readonly class BellChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof Model || ! $notification instanceof Notice) {
            return;
        }

        $notice = new StoredNotice([
            'id' => $notification->id,
            'type' => $notification->key(),
            'data' => $notification->data,
            'read_at' => null,
        ]);
        $notice->notifiable()->associate($notifiable);
        $notice->save();
    }
}
