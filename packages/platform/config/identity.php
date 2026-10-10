<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | The fallback behind `SettingKey::IDENTITY_REGISTRATION_ENABLED`, used
    | until someone saves a value from the settings screen. Open by default:
    | an instance nobody has configured yet still has to let its first owner
    | in, and `RegistrationController` keeps that possible even when this is
    | off — see its docblock on the empty-instance case.
    |
    */

    'registration' => [
        'enabled' => (bool) env('REGISTRATION_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    |
    | The fallback behind `SettingKey::IDENTITY_EMAIL_VERIFICATION_REQUIRED`.
    | Off by default: switching it on locks out every account that has not
    | verified yet, including every account that already exists, so it has to
    | be a deliberate act rather than a default somebody inherits.
    |
    | A code rather than a signed link, because a code is channel-agnostic: six
    | digits work in an email, in a WhatsApp message, in an SMS or read out on
    | the phone, and a signed URL only ever works in the first of those.
    | `Enums\VerificationChannel` is where a second channel is added.
    |
    | `length` is digits, not bytes — this is a short-lived code checked against
    | a per-user attempt limit, not a key. Six digits with five attempts inside
    | ten minutes is 5 guesses out of a million; lengthening it protects nothing
    | the attempt limit does not already protect, and costs a person accuracy.
    |
    */

    'verification' => [
        'required' => filter_var(env('IDENTITY_EMAIL_VERIFICATION_REQUIRED', false), FILTER_VALIDATE_BOOL),
        'code_length' => (int) env('IDENTITY_VERIFICATION_CODE_LENGTH', 6),
        'ttl_seconds' => (int) env('IDENTITY_VERIFICATION_TTL_SECONDS', 600),
        // Tried against one code before it is dead, regardless of what the rate limiter allows: a
        // limiter lives in the cache and a cache can be flushed, while this is a column.
        'max_attempts' => (int) env('IDENTITY_VERIFICATION_MAX_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | TOTP (BLUEPRINT.md §4.7 — the MFA fallback)
    |--------------------------------------------------------------------------
    |
    | `clock_skew_periods` is OTPHP\TOTP::verify()'s $leeway, expressed in
    | whole 30-second periods either side of "now" — RFC 6238 recommends
    | allowing at least one period of drift for a normal authenticator clock.
    |
    */

    'totp' => [
        'issuer' => env('APP_NAME'),
        'digits' => 6,
        'period_seconds' => 30,
        'clock_skew_periods' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery codes
    |--------------------------------------------------------------------------
    */

    'recovery_codes' => [
        'count' => 10,
        // Bytes of entropy before encoding, not the rendered string length.
        'entropy_bytes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Second factor
    |--------------------------------------------------------------------------
    |
    | How long a correct password stays good for while the second factor is
    | entered. Past this the password has to be given again: a half-finished
    | login left open on a shared computer should not wait indefinitely for
    | whoever sits down next.
    |
    */

    /*
     * Step-up verification: how long a session stays "recently verified".
     *
     * Not `verification` above — that one is about proving an email address once. This is the window
     * during which an already-signed-in session may perform a sensitive act without proving, again
     * and now, that the person at the keyboard holds the account's second factor.
     *
     * Long enough that a flow with a human reading an email in the middle of it is not interrupted;
     * short enough that a walked-away-from laptop is not still elevated. It is a setting because the
     * right answer depends on who uses the installation and where.
     */
    'step_up' => [
        'window_seconds' => (int) env('IDENTITY_STEP_UP_WINDOW_SECONDS', 1800),
    ],

    'mfa' => [
        'pending_ttl_seconds' => (int) env('IDENTITY_MFA_PENDING_TTL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (BLUEPRINT.md §4.7 — every auth endpoint is rate limited)
    |--------------------------------------------------------------------------
    |
    | Two independent limiters guard login: one keyed per-account
    | (email blind index + IP) so a distributed guesser cannot exhaust a
    | single victim account from many IPs without also tripping the
    | per-IP limiter below, and one keyed per-IP alone so a single attacker
    | cannot be locked out of nothing while grinding through many accounts.
    | Neither limiter can be tripped by traffic aimed at a *different*
    | account or a *different* IP — see docs/handoff/017-authentication.md
    | "How rate limiting is keyed" for the full reasoning.
    |
    */

    'rate_limits' => [
        'login_per_account_ip' => ['max_attempts' => 5, 'decay_seconds' => 60],
        'login_per_ip' => ['max_attempts' => 20, 'decay_seconds' => 60],
        'mfa_challenge_per_user' => ['max_attempts' => 5, 'decay_seconds' => 60],
        // Step-up, keyed the same way and for the same reason: a six-digit code belongs to one
        // account, so grinding at it from many addresses must still exhaust one budget.
        'step_up_per_user' => ['max_attempts' => 5, 'decay_seconds' => 60],
        /*
         * Confirming a password at the same gate, on a far tighter leash.
         *
         * A code is six digits that stop being valid within the minute, so five tries a minute gets a
         * guesser nowhere. A password does not rotate, and the same five-a-minute spent against one is
         * a slow oracle running against the account's real secret — on a session that, by the premise
         * of this gate, may not be the owner's. Five every five minutes keeps a fumbled password
         * recoverable and makes grinding pointless.
         *
         * Counted separately from the code budget above so neither can exhaust the other.
         */
        'step_up_password_per_user' => ['max_attempts' => 5, 'decay_seconds' => 300],
        'passkey_ceremony_per_ip' => ['max_attempts' => 20, 'decay_seconds' => 60],
        'register_per_ip' => ['max_attempts' => 10, 'decay_seconds' => 300],
        // Submitting a code, and asking for another. The first stops a six-digit code being guessed;
        // the second stops somebody else's inbox being used as a weapon.
        'email_verify_per_user' => ['max_attempts' => 5, 'decay_seconds' => 60],
        'email_resend_per_user' => ['max_attempts' => 3, 'decay_seconds' => 300],
    ],

];
