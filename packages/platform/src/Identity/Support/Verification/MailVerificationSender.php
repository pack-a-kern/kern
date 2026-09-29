<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support\Verification;

use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\Contracts\VerificationSender;
use Kern\Platform\Identity\Enums\VerificationChannel;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Notifications\VerifyEmailNotification;

/**
 * Sends a code to the address on the account.
 *
 * The one place in this codebase that decrypts an email in order to send something to it. That is
 * unavoidable and it is the point: the address is stored encrypted precisely so that nothing *reads*
 * it casually, and delivering to it is the one operation that has to. `User::$email_ciphertext` goes
 * through `Casts\Encrypted`, so the decryption happens on attribute read and the plaintext exists for
 * the length of this call.
 *
 * `canReach()` is false for an account with no address — an erased one, whose `email_ciphertext` is a
 * tombstone, or a passkey-only account created before an address was required. Asked before a
 * challenge is written, so no code is generated for a message that cannot be sent.
 */
final readonly class MailVerificationSender implements VerificationSender
{
    public function channel(): VerificationChannel
    {
        return VerificationChannel::EMAIL;
    }

    public function canReach(User $user): bool
    {
        return $this->address($user) !== '';
    }

    public function destination(User $user): string
    {
        return Normalizer::email($this->address($user));
    }

    public function send(User $user, string $code, int $ttlSeconds): void
    {
        // `notify()` rather than the Mail facade: Laravel resolves the address through
        // `User::routeNotificationForMail()`, which is the one place that mapping is written down.
        $user->notify(new VerifyEmailNotification($code, $ttlSeconds));
    }

    private function address(User $user): string
    {
        $email = $user->email_ciphertext;

        return is_string($email) ? trim($email) : '';
    }
}
