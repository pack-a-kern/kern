<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Kern\Platform\Identity\Enums\MfaMethodType;
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
 * A confirmed TOTP method. Not an unconfirmed one — enrolment started and abandoned proves nothing.
 * Not recovery codes on their own either: they are the way back in when the factor is lost, and an
 * account whose only factor is a sheet of one-time codes cannot be asked to prove itself routinely.
 *
 * **A passkey is deliberately not accepted here yet**, although it is accepted as a sign-in factor.
 * Not an oversight and not a judgement about passkeys: the step-up endpoint has no assertion path
 * yet, so accepting one as satisfying this would strand a passkey-only account at a dialog it could
 * never answer. The two refusals are told apart in the response for exactly that reason.
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
     * Whether this account holds a factor the step-up dialog can actually ask for.
     *
     * False means the person must enrol something before a protected act is possible at all — a
     * different answer from "verify now", and the caller must not conflate them.
     */
    public function canVerify(User $user): bool
    {
        return $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNotNull('confirmed_at')
            ->exists();
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
