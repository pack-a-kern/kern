<?php

declare(strict_types=1);

namespace Kern\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Kern\Notifications\Channels\BellChannel;
use Kern\Notifications\Channels\PushChannel;
use Kern\Notifications\Contracts\NoticeType;

/**
 * One notice of a declared kind, sent like any Laravel notification: `$person->notify(new Notice(…))`.
 *
 * Three ways out, always the same three. Stored for the bell with `$data` only; announced on the
 * person's private channel with nothing but its id and kind, so a socket never carries what the bell
 * shows; and sent to the person's devices when their switch for the kind is on. The words are the
 * kind's (`NoticeType::render()`), written each time the notice is shown.
 *
 * `$data` is stored as plain text: ids, counts and codes — what `render()` needs to find the rest.
 */
final class Notice extends Notification
{
    /**
     * @param class-string<NoticeType> $type
     * @param array<string, scalar|null> $data
     */
    public function __construct(public readonly string $type, public readonly array $data)
    {
        if (! is_subclass_of($type, NoticeType::class)) {
            throw new InvalidArgumentException("[{$type}] is not a kind of notice.");
        }
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [BellChannel::class, 'broadcast', PushChannel::class];
    }

    public function key(): string
    {
        return ($this->type)::key();
    }

    public function broadcastType(): string
    {
        return $this->key();
    }

    /** Laravel adds the notice's id and kind; the page fetches the words over HTTPS. */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([]);
    }
}
