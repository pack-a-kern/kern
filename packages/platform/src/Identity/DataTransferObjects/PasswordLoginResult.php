<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\DataTransferObjects;

use Kern\Platform\Identity\Models\User;

/**
 * What `Kern\Platform\Identity\Actions\AttemptPasswordLogin` returns on success. `mfaRequired` tells
 * the HTTP layer whether it may establish a session immediately (`Auth::login()` +
 * `$request->session()->regenerate()`, `Kern\Platform\Identity\Support\SessionRegistrar::register()`)
 * or must instead stash a short-lived "password verified, awaiting MFA" marker and wait for
 * `Kern\Platform\Identity\Actions\CompleteMfaChallenge` — this class carries no session/request
 * concept itself, per this module's "Actions stay framework-session-free" convention (see
 * `Kern\Platform\Identity\Support\SessionRegistrar`'s own docblock).
 */
final readonly class PasswordLoginResult
{
    public function __construct(
        public User $user,
        public bool $mfaRequired,
    ) {
    }
}
