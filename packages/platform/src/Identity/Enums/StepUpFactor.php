<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Enums;

/**
 * What a session can be asked for when it has to prove, again, who is holding it.
 *
 * Ordered strongest first, and that order is the whole policy: an account is challenged on the best
 * factor it has, never on a weaker one it also happens to have.
 * {@see \Kern\Platform\Identity\Support\StepUpPolicy::challengeFor()} does the choosing.
 *
 * ## Why a password counts at all
 *
 * It is not a second factor — it is the same factor the session was opened with. What it proves is
 * narrower and still worth having: that whoever is acting now can produce the secret, not merely
 * that they hold a cookie. Against the threat these routes are guarded for — a stolen session, a
 * borrowed laptop, a token lifted by a script — that is exactly the gap.
 *
 * Before this existed, an account with no authenticator app was not challenged at all, so the gate
 * applied to the minority who had armed themselves and to nobody else. A weaker challenge for them
 * is not a weakening of anything; it is the first challenge there has ever been.
 *
 * ## Why a passkey is missing
 *
 * It is a stronger factor than either of these, and it is accepted at sign-in. It is absent here
 * because the step-up endpoint has no assertion ceremony: offering it would raise a dialog that
 * cannot be answered. Until that exists, an account holding only a passkey — which the `users`
 * schema permits, though nothing registers one today — is not challenged.
 */
enum StepUpFactor: string
{
    /** A code from the authenticator app, or one of the account's unused recovery codes. */
    case TOTP = 'totp';

    /** The account's own password, typed again. */
    case PASSWORD = 'password';

    /**
     * The refusal code a guarded route answers with when this is the factor to ask for.
     *
     * Two codes rather than one with a field beside it: the client already routes on `reason`, it is
     * already the vocabulary the translations are keyed by, and the two refusals genuinely ask for
     * different things — one wants six digits, the other a password manager's help.
     */
    public function refusalReason(): string
    {
        return match ($this) {
            self::TOTP => 'VERIFICATION_REQUIRED',
            self::PASSWORD => 'PASSWORD_CONFIRMATION_REQUIRED',
        };
    }
}
