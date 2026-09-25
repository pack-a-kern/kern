<?php

declare(strict_types=1);

use App\Domain\Access\Services\AccessCatalogue;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    Artisan::call('kern:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** `getKey()` is `mixed` to static analysis; the model declares a string key. */
function userId(User $user): string
{
    $key = $user->getKey();

    return is_scalar($key) ? (string) $key : '';
}

it('refuses every administrative route to a user with no platform role', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/settings')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/audit/summary')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/admin/users')->assertForbidden();
});

it('separates reading settings from changing them', function (): void {
    // ADMIN holds system.settings.view but not system.settings.manage — the distinction exists so
    // an auditor can read configuration without being able to redirect taxpayer documents.
    $admin = userWithRole('ADMIN');

    $this->actingAs($admin)->getJson('/api/v1/settings')->assertOk();
    $payload = ['settings' => ['spv.messages_window_days' => '90']];

    $this->actingAs($admin)->putJson('/api/v1/settings/group/spv', $payload)->assertForbidden();

    $this->actingAs(userWithRole('SUPER_ADMIN'))->putJson('/api/v1/settings/group/spv', $payload)->assertOk();
});

it('refuses to remove the last super administrator', function (): void {
    $only = userWithRole('SUPER_ADMIN');
    $actor = userWithRole('SUPER_ADMIN');

    // With two, demoting one is allowed.
    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . userId($only) . '/roles', ['roles' => ['VIEWER']])
        ->assertOk();

    // The actor is now the only one left, and cannot demote themselves out of existence.
    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . userId($actor) . '/roles', ['roles' => ['VIEWER']])
        ->assertStatus(422);
});

it('refuses to strip your own last role', function (): void {
    $actor = userWithRole('SUPER_ADMIN');

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . userId($actor) . '/roles', ['roles' => []])
        ->assertStatus(422);
});

it('refuses to suspend your own account', function (): void {
    $actor = userWithRole('SUPER_ADMIN');

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . userId($actor) . '/status', ['status' => 'SUSPENDED'])
        ->assertStatus(422);
});

it('writes a role change into the audit chain', function (): void {
    $actor = userWithRole('SUPER_ADMIN');
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . userId($target) . '/roles', ['roles' => ['ACCOUNTANT']])
        ->assertOk();

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'ROLE_CHANGED',
        'resource_type' => 'user',
        'resource_id' => (string) $target->getKey(),
    ]);
});

it('reports the matrix from the database, not from the config file', function (): void {
    $response = $this->actingAs(userWithRole('SUPER_ADMIN'))->getJson('/api/v1/admin/access')->assertOk();

    expect($response->json('roles'))->toContain('SUPER_ADMIN', 'ADMIN', 'ACCOUNTANT', 'VIEWER');

    /** @var array<string, bool> $holders */
    $holders = $response->json('groups.0.permissions.1.roles');

    expect($response->json('groups.0.group'))->toBe('platform')
        ->and($response->json('groups.0.permissions.1.permission'))->toBe('system.settings.manage')
        ->and($response->json('groups.0.permissions.1.enforced'))->toBeTrue()
        ->and($holders['SUPER_ADMIN'])->toBeTrue()
        ->and($holders['ADMIN'])->toBeFalse();
});

it('marks permissions that nothing checks yet as unenforced', function (): void {
    $catalogue = app(AccessCatalogue::class);

    expect($catalogue->isEnforced('system.settings.manage'))->toBeTrue()
        ->and($catalogue->isEnforced('documents.decrypt'))->toBeFalse();
});
