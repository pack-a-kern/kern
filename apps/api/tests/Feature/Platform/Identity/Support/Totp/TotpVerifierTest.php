<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\Support\Totp\TotpVerifierTest;

use Kern\Platform\Identity\Support\Totp\TotpVerifier;
use OTPHP\TOTP;

/**
 * Pure, DB-free unit tests for the TOTP verification primitive `BLUEPRINT.md` §4.7 relies on
 * (`docs/CODE-QUALITY.md` §5 — "many pure unit tests, no database" applies just as much to a
 * security primitive as to a tax rule).
 */
it('accepts the current code', function (): void {
    $verifier = new TotpVerifier();
    $secret = $verifier->generateSecret();
    $code = TOTP::createFromSecret($secret)->now();

    expect($verifier->verify($secret, $code))->toBeTrue();
});

it('rejects a wrong code', function (): void {
    $verifier = new TotpVerifier();
    $secret = $verifier->generateSecret();

    expect($verifier->verify($secret, '000000'))->toBeFalse();
});

it('rejects an empty code without throwing', function (): void {
    $verifier = new TotpVerifier();
    $secret = $verifier->generateSecret();

    expect($verifier->verify($secret, ''))->toBeFalse();
});

it('tolerates one period of clock skew in either direction', function (): void {
    $verifier = new TotpVerifier();
    $secret = $verifier->generateSecret();
    $totp = TOTP::createFromSecret($secret);
    $now = 1_700_000_000; // fixed reference instant, irrelevant which one

    expect($verifier->verify($secret, $totp->at($now - 30), $now))->toBeTrue();
    expect($verifier->verify($secret, $totp->at($now + 30), $now))->toBeTrue();
    expect($verifier->verify($secret, $totp->at($now), $now))->toBeTrue();
});

it('rejects a code more than one period away', function (): void {
    $verifier = new TotpVerifier();
    $secret = $verifier->generateSecret();
    $totp = TOTP::createFromSecret($secret);
    $now = 1_700_000_000;

    expect($verifier->verify($secret, $totp->at($now - 60), $now))->toBeFalse();
    expect($verifier->verify($secret, $totp->at($now + 90), $now))->toBeFalse();
});

it('generates a usable provisioning URI carrying the configured issuer', function (): void {
    $verifier = new TotpVerifier();
    $secret = $verifier->generateSecret();

    $uri = $verifier->provisioningUri($secret, 'ion@example.com');

    expect($uri)->toStartWith('otpauth://totp/');
    expect($uri)->toContain('ion%40example.com');
    expect($uri)->toContain('secret=' . $secret);
});
