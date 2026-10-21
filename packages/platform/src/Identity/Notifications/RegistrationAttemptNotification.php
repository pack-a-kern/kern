<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The mail to an address that already has an account, when someone tries to register it again.
 *
 * The registration form answers every address alike (`Jobs\RegisterAccount`), so this is the only
 * place the difference shows — in the owner's inbox, where only the owner reads it.
 *
 * In Romanian and English, one after the other: it arrives before any session, so there is no
 * language the person chose to read it in, and a guess would be wrong for half of them. The words are
 * in `packages/platform/lang`.
 *
 * Like `VerifyEmailNotification`: mail only, and nothing in it but the fact — no link to follow, no
 * name, no account id, nothing about a taxpayer.
 */
final class RegistrationAttemptNotification extends Notification
{
    /** The languages the mail is written in, in the order it shows them. */
    private const array LOCALES = ['ro', 'en'];

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())->subject(implode(' / ', array_map(
            static fn (string $locale): string => self::line('subject', $locale),
            self::LOCALES,
        )));

        foreach (self::LOCALES as $locale) {
            $message->line(self::line('tried', $locale))
                ->line(self::line('if_you', $locale))
                ->line(self::line('if_not', $locale));
        }

        return $message;
    }

    private static function line(string $key, string $locale): string
    {
        $line = __('platform::registration.attempt.' . $key, [], $locale);

        return is_string($line) ? $line : '';
    }
}
