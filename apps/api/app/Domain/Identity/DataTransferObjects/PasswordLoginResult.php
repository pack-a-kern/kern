<?php

declare(strict_types=1);

namespace App\Domain\Identity\DataTransferObjects;

use App\Domain\Identity\Models\User;

/**
 * What `App\Domain\Identity\Actions\AttemptPasswordLogin` returns on success. `mfaRequired` tells
 * the HTTP layer whether it may establish a session immediately (`Auth::login()` +
 * `$request->session()->regenerate()`, `App\Domain\Identity\Support\SessionRegistrar::register()`)
 * or must instead stash a short-lived "password verified, awaiting MFA" marker and wait for
 * `App\Domain\Identity\Actions\CompleteMfaChallenge` — this class carries no session/request
 * concept itself, per this module's "Actions stay framework-session-free" convention (see
 * `App\Domain\Identity\Support\SessionRegistrar`'s own docblock).
 */
final readonly class PasswordLoginResult
{
    public function __construct(
        public User $user,
        public bool $mfaRequired,
    ) {
    }
}
