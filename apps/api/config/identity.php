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
    | TOTP (BLUEPRINT.md §4.7 — the MFA fallback)
    |--------------------------------------------------------------------------
    |
    | `clock_skew_periods` is OTPHP\TOTP::verify()'s $leeway, expressed in
    | whole 30-second periods either side of "now" — RFC 6238 recommends
    | allowing at least one period of drift for a normal authenticator clock.
    |
    */

    'totp' => [
        'issuer' => env('APP_NAME', 'TaxOS România'),
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
        'passkey_ceremony_per_ip' => ['max_attempts' => 20, 'decay_seconds' => 60],
        'register_per_ip' => ['max_attempts' => 10, 'decay_seconds' => 300],
    ],

];
