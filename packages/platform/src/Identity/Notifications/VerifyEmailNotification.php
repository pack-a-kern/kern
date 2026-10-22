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
 * ## Where the wording is
 *
 * Not in the interface's dictionaries — this is read in an inbox, not in the application — but in
 * `platform::verification.mail`. It is sent in the server's locale: the person has no session yet,
 * so nothing tells this class which language they read.
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
            ->subject(__('platform::verification.mail.subject'))
            ->line(__('platform::verification.mail.intro'))
            ->line($this->code)
            ->line(__('platform::verification.mail.validity', ['minutes' => $minutes]))
            ->line(__('platform::verification.mail.ignore'));
    }
}
