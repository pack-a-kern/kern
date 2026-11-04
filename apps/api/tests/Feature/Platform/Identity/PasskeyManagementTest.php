<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\PasskeyManagementTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserPasskey;
use Tests\TestCase;

/**
 * Seeing your own passkeys, and taking one away: `GET` / `DELETE api/v1/auth/passkeys`.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

function signedIn(TestCase $test, string $email = 'owner@example.com'): User
{
    $user = User::factory()->withEmail($email)->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();

    carryCookiesFrom($test->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'correct-horse-battery-staple',
    ])->assertOk());

    return $user;
}

function passkeyFor(User $user, string $name = 'Laptop', bool $synced = false): UserPasskey
{
    return UserPasskey::query()->create([
        'user_id' => $user->id,
        'credential_id' => base64_encode(random_bytes(16)),
        'public_key' => base64_encode(random_bytes(32)),
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'attestation_type' => 'none',
        'transports' => [],
        'sign_count' => 0,
        'backup_eligible' => $synced,
        'backup_status' => $synced,
        'name' => $name,
    ]);
}

it('lists only the account’s own passkeys, with nothing a login could use', function (): void {
    $owner = signedIn($this);
    $mine = passkeyFor($owner, 'Phone', true);
    passkeyFor(User::factory()->withEmail('other@example.com')->create(), 'Not mine');

    $response = $this->getJson('/api/v1/auth/passkeys')->assertOk();

    expect($response->json('passkeys'))->toHaveCount(1)
        ->and($response->json('passkeys.0.id'))->toBe($mine->id)
        ->and($response->json('passkeys.0.name'))->toBe('Phone')
        ->and($response->json('passkeys.0.synced'))->toBeTrue()
        ->and($response->json('passkeys.0.last_used_at'))->toBeNull()
        ->and(array_keys((array) $response->json('passkeys.0')))->toBe(['id', 'name', 'synced', 'created_at', 'last_used_at']);
});

it('removes a passkey of the account’s own, and records it', function (): void {
    $owner = signedIn($this);
    $passkey = passkeyFor($owner);
    verifySession();

    $this->deleteJson("/api/v1/auth/passkeys/{$passkey->id}")->assertNoContent();

    expect(UserPasskey::query()->whereKey($passkey->id)->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('chain_key', AuditChainService::SYSTEM_CHAIN_KEY)
            ->where('event_type', 'PasskeyRemoved')->where('resource_id', $passkey->id)->exists())->toBeTrue();
});

it('answers another account’s passkey as one that does not exist, and keeps it', function (): void {
    signedIn($this);
    $theirs = passkeyFor(User::factory()->withEmail('other@example.com')->create());
    verifySession();

    $this->deleteJson("/api/v1/auth/passkeys/{$theirs->id}")->assertNotFound();
    $this->deleteJson('/api/v1/auth/passkeys/01a1a7f2-0000-7000-8000-000000000000')->assertNotFound();

    expect(UserPasskey::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('refuses to remove a passkey without the password being confirmed again', function (): void {
    $owner = signedIn($this);
    $passkey = passkeyFor($owner);
    forgetVerification();

    $this->deleteJson("/api/v1/auth/passkeys/{$passkey->id}")
        ->assertStatus(403)
        ->assertJsonPath('reason', 'PASSWORD_CONFIRMATION_REQUIRED');

    expect(UserPasskey::query()->whereKey($passkey->id)->exists())->toBeTrue();
});

it('never removes the last way into an account that has no password', function (): void {
    $owner = signedIn($this);
    $only = passkeyFor($owner);
    $owner->forceFill(['password_hash' => null])->save();
    // The guard keeps the user it signed in for the rest of this test; a real request loads it afresh.
    app('auth')->forgetGuards();
    verifySession();

    $this->deleteJson("/api/v1/auth/passkeys/{$only->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('passkey');

    expect(UserPasskey::query()->whereKey($only->id)->exists())->toBeTrue();

    // With a second one there is still a way in, so the first may go.
    passkeyFor($owner, 'Backup');
    $this->deleteJson("/api/v1/auth/passkeys/{$only->id}")->assertNoContent();
});

it('shows nothing to a visitor who is not signed in', function (): void {
    $this->getJson('/api/v1/auth/passkeys')->assertUnauthorized();
});
