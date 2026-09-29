<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The mail carrying a verification code.
 *
 * `mail` only, and deliberately not `database`: the bell is for things a signed-in person catches up
 * on, and a code that expires in ten minutes is not one of them. It would also put the code in a
 * table this application does not encrypt.
 *
 * ## Why the wording is here and not in the dictionaries
 *
 * Everything a person reads *in the application* is translated, and this is not in the application —
 * it is in their inbox, before they have a session, with no locale to read from. Laravel's own
 * `trans()` would use the server's default, which is not a translation so much as a guess. So the
 * subject and body are the framework's mail language lines, which ship translated, and the only
 * thing this class writes is the code itself.
 *
 * ## What is not in it
 *
 * No link. A code is the whole point — it works in an email, in a message, or read out loud, and a
 * signed URL only works in the first. See `Enums\VerificationChannel`.
 *
 * No name, no account id, nothing about the taxpayer. Mail leaves the installation, and a subject
 * line is the least private place in a person's life.
 */
final class VerifyEmailNotification extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly int $ttlSeconds,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) max(1, round($this->ttlSeconds / 60));

        return (new MailMessage())
            ->subject(__('Confirm your email address'))
            ->line(__('Enter this code to confirm your email address:'))
            ->line($this->code)
            ->line(__('The code is valid for :minutes minutes.', ['minutes' => $minutes]))
            ->line(__('If you did not ask for this, you can ignore this message.'));
    }
}
