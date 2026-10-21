<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\RateLimiter;
use Kern\Platform\Identity\Actions\RegisterUser;
use Kern\Platform\Identity\Exceptions\EmailAlreadyRegisteredException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Notifications\RegistrationAttemptNotification;

/**
 * What a registration does, after the person who asked has already had their answer.
 *
 * ## Why the request does not do it
 *
 * `POST /api/v1/auth/register` answers every address the same, so that it cannot be asked whether an
 * address has an account here. The words were the easy half. The time was the other: a new account is
 * a row, a data key from the key manager and an audit event, and an address that is taken is one
 * lookup — a difference anyone with a stopwatch could read the answer from. Done here instead, the
 * request costs the same for both: hash the password, queue this, answer.
 *
 * ## What it does
 *
 * A new address becomes an account, as it always did. A taken one is left alone, and its owner is told
 * someone tried to register with it — at most once per `identity.rate_limits.registration_notice_per_account`,
 * so the form cannot be used to fill somebody's inbox.
 *
 * Encrypted on the queue: it carries an email address and a password hash.
 */
final class RegisterAccount implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly ?string $passwordHash,
    ) {
    }

    public function handle(RegisterUser $register): void
    {
        try {
            $register($this->email, $this->passwordHash);
        } catch (EmailAlreadyRegisteredException) {
            $this->tellTheOwner();
        }
    }

    private function tellTheOwner(): void
    {
        $owner = User::query()->whereEmail($this->email)->first();

        if ($owner === null) {
            return;
        }

        RateLimiter::attempt(
            'registration-notice:' . $owner->id,
            config()->integer('identity.rate_limits.registration_notice_per_account.max_attempts'),
            static fn () => $owner->notify(new RegistrationAttemptNotification()),
            config()->integer('identity.rate_limits.registration_notice_per_account.decay_seconds'),
        );
    }
}
