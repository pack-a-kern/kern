<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\SpvMfaPolicyTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Exceptions\MfaNotEnrolledException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Identity\Models\UserPasskey;
use Kern\Platform\Identity\Support\SpvMfaPolicy;

/**
 * `BLUEPRINT.md` §4.7's "MFA mandatory for accounts connecting SPV" — expressed here as the
 * enforceable capability check Milestone 10 will call before creating an `SpvConnection`
 * (`Kern\Platform\Identity\Support\SpvMfaPolicy`'s own docblock). SPV itself does not exist yet; this
 * is the only thing this task's brief asked to prove about it.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('denies a user with no MFA method and no passkey', function (): void {
    $user = User::factory()->create();

    expect((new SpvMfaPolicy())->hasStrongAuthentication($user))->toBeFalse();
    expect(fn () => (new SpvMfaPolicy())->ensureStrongAuthentication($user))
        ->toThrow(MfaNotEnrolledException::class);
});

it('allows a user with a confirmed TOTP method', function (): void {
    $user = User::factory()->create();
    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);

    expect((new SpvMfaPolicy())->hasStrongAuthentication($user))->toBeTrue();
    expect(fn () => (new SpvMfaPolicy())->ensureStrongAuthentication($user))->not->toThrow(MfaNotEnrolledException::class);
});

it('denies a user with an unconfirmed (abandoned) TOTP enrollment', function (): void {
    $user = User::factory()->create();
    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => null,
    ]);

    expect((new SpvMfaPolicy())->hasStrongAuthentication($user))->toBeFalse();
});

it('allows a user with a registered passkey, even with no TOTP', function (): void {
    $user = User::factory()->create();
    UserPasskey::create([
        'user_id' => $user->id,
        'credential_id' => base64_encode(random_bytes(32)),
        'public_key' => base64_encode(random_bytes(64)),
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'attestation_type' => 'none',
        'transports' => [],
        'sign_count' => 0,
    ]);

    expect((new SpvMfaPolicy())->hasStrongAuthentication($user))->toBeTrue();
});
