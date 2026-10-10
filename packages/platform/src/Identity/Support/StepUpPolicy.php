<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Enums\StepUpFactor;
use Kern\Platform\Identity\Models\User;

/**
 * Whether a session may perform a sensitive act right now, or must prove itself again first.
 *
 * ## Why proof and not enrolment
 *
 * The check this replaced asked whether an account *had* enrolled a second factor, with `exists()`,
 * and nothing more. Against the threat it was named for — somebody holding a stolen password or a
 * live session — that answers nothing: the row exists, the check passes, and the factor is never
 * used. `BLUEPRINT.md` §4.7 requires MFA of accounts that reach the tax authority; a requirement met
 * once at enrolment and never again is a requirement on history.
 *
 * So the question became *when did this session last prove it*, which is a timestamp, which expires.
 *
 * ## Why the timestamp lives in the session and not in `user_sessions`
 *
 * It was a column first. A column is a second place remembering what the session already is, keyed by
 * an id the two sides have to agree on — and they do not always: a request arriving without its cookie
 * gets a fresh session, which the row then describes wrongly rather than not at all. Elevation is a
 * property of a session, so it belongs *in* it:
 *
 * - it cannot be recorded against the wrong session, by construction;
 * - destroying the session destroys it, so signing out or revoking withdraws it with nothing extra to
 *   remember and no second thing to keep in step;
 * - a guarded route costs no query to check it.
 *
 * What a column would have bought — "this session was verified 3 minutes ago" in the device list — is
 * not something anybody asked for, and the audit trail already records every verification.
 *
 * ## What counts as a factor it can ask for
 *
 * A confirmed TOTP method, and failing that the account's own password. Not an unconfirmed TOTP
 * method — enrolment started and abandoned proves nothing. Not recovery codes on their own either:
 * they are the way back in when the factor is lost, and an account whose only factor is a sheet of
 * one-time codes cannot be asked to prove itself routinely. They remain accepted *as* an answer to a
 * TOTP challenge, which is `CompleteMfaChallenge`'s business rather than this policy's.
 *
 * The password was not always here, and the gate was the poorer for it: an account with no
 * authenticator app was waved through every guarded route, so the only people it stopped were the
 * ones who had already armed themselves. A password is the same factor the session was opened with
 * and proves less than a code does — but what it proves is the thing in question, that whoever is
 * acting can produce the secret and not merely hold the cookie.
 *
 * **A passkey is deliberately not counted**, although it is accepted as a sign-in factor: the
 * step-up endpoint has no assertion path yet, so an account whose only factor is a passkey would be
 * sent to a dialog it could never answer. The consequence is stated rather than hidden — an account
 * with neither TOTP nor a password is not gated, and the `users` schema permits exactly that shape,
 * though nothing creates one today. {@see \Kern\Platform\Identity\Enums\StepUpFactor}.
 */
final class StepUpPolicy
{
    /**
     * When this session last proved itself, as a Unix timestamp.
     *
     * Namespaced under `auth.` beside the login flow's own pending marker, and holding an integer
     * rather than a `Carbon`: a session payload is serialised, and a scalar survives that without
     * depending on how any particular driver encodes an object.
     */
    private const string VERIFIED_AT_KEY = 'auth.step_up_verified_at';

    private const int DEFAULT_WINDOW_SECONDS = 1800;

    /**
     * What this account should be asked for, or null when there is nothing it can be asked.
     *
     * The best factor it holds, never a weaker one it also holds: an account with an authenticator
     * app is asked for the code, and is not offered its password instead. A gate that accepts either
     * is worth exactly its weaker half, and losing the phone is already answered — the TOTP path
     * takes an unused recovery code, so the password is not needed as a way around it.
     *
     * Null means the account holds neither, which today is only possible for a passkey-only account
     * — permitted by the `users` schema, created by nothing. Such a request is not held up, and
     * {@see \Kern\Platform\Identity\Enums\StepUpFactor} says why that is the honest answer rather
     * than a dialog nobody could satisfy.
     */
    public function challengeFor(User $user): ?StepUpFactor
    {
        $hasTotp = $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNotNull('confirmed_at')
            ->exists();

        if ($hasTotp) {
            return StepUpFactor::TOTP;
        }

        /* Read from the model rather than queried: `password_hash` is on the row the request already
           loaded, and an empty string is as absent as a null for this purpose. */
        return ($user->password_hash ?? '') === '' ? null : StepUpFactor::PASSWORD;
    }

    public function markVerified(Session $session): void
    {
        $session->put(self::VERIFIED_AT_KEY, Carbon::now()->getTimestamp());
    }

    /**
     * Whether this session's last proof is still inside the window.
     *
     * An absent marker is the normal state of a session that has never been asked, and is false here
     * rather than an error: never having proved and having proved too long ago lead to the same place,
     * which is the dialog. Anything that is not an integer is treated the same way — a payload that
     * has been tampered with or written by an older version proves nothing either.
     */
    public function isRecentlyVerified(Session $session): bool
    {
        $verifiedAt = $session->get(self::VERIFIED_AT_KEY);

        if (! is_int($verifiedAt)) {
            return false;
        }

        return $verifiedAt > Carbon::now()->getTimestamp() - $this->windowSeconds();
    }

    /**
     * Falls back to the shipped default rather than to zero when the value is missing or not an
     * integer: a misconfigured window must not become "never verified", which would lock every
     * guarded route for everybody — the same shape as `TotpVerifier`'s own reads.
     */
    public function windowSeconds(): int
    {
        $configured = config('identity.step_up.window_seconds');
        $value = is_int($configured) ? $configured : self::DEFAULT_WINDOW_SECONDS;

        return $value > 0 ? $value : self::DEFAULT_WINDOW_SECONDS;
    }
}
