<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\AdminActionsRequireStepUpTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\StepUpPolicy;
use Spatie\Permission\PermissionRegistrar;

/**
 * The admin user-management actions — minting an account, changing roles or status, erasing, and
 * impersonating — are the most powerful in the installation, so each sits behind `verify-recent`.
 * A stolen or walked-away admin session must not reach them on a cookie alone; the gate asks for a
 * password the holder of a mere session does not have.
 *
 * docs/SECURITY-FINDINGS.md — the admin-actions-without-step-up finding.
 */
beforeEach(function (): void {
    Artisan::call('kern:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function admin(): User
{
    $user = User::factory()->create();
    // A real admin has a password, which is the factor `verify-recent` falls back to when no
    // authenticator is enrolled. A factory user with none would have nothing to be challenged for,
    // and the gate — correctly — would not stop it.
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    $user->assignRole('SUPER_ADMIN');

    return $user;
}

it('refuses the admin mutations on a session that has not stepped up', function (string $method, string $path, array $body): void {
    $actor = admin();
    $target = User::factory()->create();

    $this->actingAs($actor);
    forgetVerification();

    $this->json($method, str_replace('{id}', $target->id, $path), $body)
        ->assertStatus(403)
        ->assertJsonPath('reason', 'PASSWORD_CONFIRMATION_REQUIRED');
})->with([
    'create user' => ['POST', '/api/v1/admin/users', ['email' => 'new@example.test', 'roles' => ['VIEWER']]],
    'set roles' => ['PUT', '/api/v1/admin/users/{id}/roles', ['roles' => ['ACCOUNTANT']]],
    'set status' => ['PUT', '/api/v1/admin/users/{id}/status', ['status' => 'SUSPENDED']],
    'erase' => ['DELETE', '/api/v1/admin/users/{id}', []],
    'impersonate' => ['POST', '/api/v1/admin/users/{id}/impersonate', []],
]);

it('lets a stepped-up admin create a user', function (): void {
    $this->actingAs(admin());
    verifySession();

    $this->postJson('/api/v1/admin/users', ['email' => 'new@example.test', 'roles' => ['VIEWER']])
        ->assertCreated();
});

it('clears the admin step-up when impersonation starts, so the target session does not inherit it', function (): void {
    $actor = admin();
    $target = User::factory()->create();

    // The admin proves step-up, then switches into the target. The proof belonged to the admin and
    // must not survive the switch, or the target's own step-up gates fall to the admin's password.
    $this->actingAs($actor);
    verifySession();
    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeTrue();

    $this->postJson('/api/v1/admin/users/' . $target->id . '/impersonate')->assertOk();

    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeFalse();
});
