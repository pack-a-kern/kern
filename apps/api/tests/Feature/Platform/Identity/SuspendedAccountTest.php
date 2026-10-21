<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\SuspendedAccountTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserSession;
use Spatie\Permission\PermissionRegistrar;

/**
 * A suspended account cannot act — not by signing in, and not with a session it already held.
 *
 * The column used to be written and never read: suspending changed a label and nothing else. These
 * tests hold the three places that now enforce it: the password login, the admin action that suspends,
 * and the middleware that ends any session an inactive account still carries.
 */
beforeEach(function (): void {
    Artisan::call('kern:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // Suspending/reactivating an account is now behind `verify-recent`; step the admin session up
    // first, as a real one does, so these tests reach the suspension behaviour they assert.
    verifySession();
});

function suspendedUser(string $email, string $password): User
{
    $user = User::factory()->withEmail($email)->create();
    $user->password_hash = Hash::make($password);
    $user->status = UserStatus::SUSPENDED;
    $user->save();

    return $user;
}

it('refuses to sign in a suspended account, and says why', function (): void {
    suspendedUser('ion@example.com', 'correct-horse-battery-staple');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertForbidden()->assertJson(['reason' => 'ACCOUNT_SUSPENDED']);

    $this->assertGuest();
    expect(UserSession::query()->count())->toBe(0);
});

it('still answers a wrong password with the generic refusal', function (): void {
    // "Suspended" is only said to someone who proved the password; a stranger learns nothing new.
    suspendedUser('ion@example.com', 'correct-horse-battery-staple');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'the-wrong-password',
    ])->assertStatus(422)->assertJsonMissing(['reason' => 'ACCOUNT_SUSPENDED']);
});

it('signs the account out everywhere when an administrator suspends it', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    $target = User::factory()->create();
    UserSession::query()->create([
        'id' => 'target-session-id',
        'user_id' => $target->id,
        'ip_address' => '10.0.0.2',
        'user_agent' => 'another-device',
        'last_used_at' => now('UTC'),
        'created_at' => now('UTC'),
    ]);

    $this->actingAs($admin)
        ->putJson('/api/v1/admin/users/' . $target->id . '/status', ['status' => 'SUSPENDED'])
        ->assertOk();

    expect(UserSession::query()->findOrFail('target-session-id')->revoked_at)->not->toBeNull();
});

it('leaves the sessions alone when an account is reactivated', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    $target = User::factory()->create();
    $target->status = UserStatus::SUSPENDED;
    $target->save();
    UserSession::query()->create([
        'id' => 'target-session-id',
        'user_id' => $target->id,
        'ip_address' => '10.0.0.2',
        'user_agent' => 'another-device',
        'last_used_at' => now('UTC'),
        'created_at' => now('UTC'),
    ]);

    $this->actingAs($admin)
        ->putJson('/api/v1/admin/users/' . $target->id . '/status', ['status' => 'ACTIVE'])
        ->assertOk();

    expect(UserSession::query()->findOrFail('target-session-id')->revoked_at)->toBeNull();
});

it('ends a session the account held from before it was suspended', function (): void {
    // The way the admin screen suspends already revokes; this is every other way the column can change.
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    User::query()->whereKey($user->id)->update(['status' => UserStatus::SUSPENDED->value]);

    $this->actingAs($user->fresh() ?? $user)
        ->getJson('/api/v1/me')
        ->assertForbidden()
        ->assertJson(['reason' => 'ACCOUNT_SUSPENDED']);

    $this->assertGuest();
});
