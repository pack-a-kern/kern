<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\Contracts;

use Kern\Platform\Notifications\DataTransferObjects\PushMessage;

/**
 * A notification that may also reach a person's devices. It adds `WebPushChannel::class` to its `via()`
 * and says which declared kind it is, so the person's switch for that kind decides whether it goes.
 */
interface SendsPush
{
    /** The `NotificationType::$key` this notification is one of. */
    public function pushType(): string;

    /** What it says on a device whose language is `$locale` — exactly what happened, in that language. */
    public function toPush(object $notifiable, string $locale): PushMessage;
}
