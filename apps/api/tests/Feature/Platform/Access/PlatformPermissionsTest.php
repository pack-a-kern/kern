<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Kern\Platform\Access\Services\AccessCatalogue;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('kern:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // The admin user-management routes now sit behind `verify-recent`. These tests exercise the
    // permission and role rules, not that gate, so step the session up first — as a real admin does.
    verifySession();
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('refuses every administrative route to a user with no platform role', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/settings')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/audit/summary')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/admin/users')->assertForbidden();
});

it('refuses the administration pages themselves to a user without their permission', function (): void {
    $user = User::factory()->create();
    $admin = userWithRole('ADMIN');

    foreach (['/admin/settings', '/admin/users'] as $page) {
        $this->actingAs($user)->get($page)->assertForbidden();
        $this->actingAs($admin)->get($page)->assertOk();
    }
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
        ->putJson('/api/v1/admin/users/' . $only->id . '/roles', ['roles' => ['VIEWER']])
        ->assertOk();

    // The actor is now the only one left, and cannot demote themselves out of existence.
    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $actor->id . '/roles', ['roles' => ['VIEWER']])
        ->assertStatus(422);
});

it('does not count a suspended super administrator as the one left', function (): void {
    // A suspended account cannot sign in to grant anything back, so it is not somebody left to
    // administer the installation. Demoting the only *active* super administrator must be refused
    // even while a suspended holder exists on paper.
    $actor = userWithRole('SUPER_ADMIN');
    $suspended = userWithRole('SUPER_ADMIN');
    $suspended->update(['status' => UserStatus::SUSPENDED]);

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $actor->id . '/roles', ['roles' => ['VIEWER']])
        ->assertStatus(422);
});

it('refuses to strip your own last role', function (): void {
    $actor = userWithRole('SUPER_ADMIN');

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $actor->id . '/roles', ['roles' => []])
        ->assertStatus(422);
});

it('refuses to suspend your own account', function (): void {
    $actor = userWithRole('SUPER_ADMIN');

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $actor->id . '/status', ['status' => 'SUSPENDED'])
        ->assertStatus(422);
});

it('writes a role change into the audit chain', function (): void {
    $actor = userWithRole('SUPER_ADMIN');
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $target->id . '/roles', ['roles' => ['ACCOUNTANT']])
        ->assertOk();

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'ROLE_CHANGED',
        'resource_type' => 'user',
        'resource_id' => $target->id,
    ]);
});

it('reports the matrix from the database, not from the config file', function (): void {
    $response = $this->actingAs(userWithRole('SUPER_ADMIN'))->getJson('/api/v1/admin/access')->assertOk();

    expect($response->json('roles'))->toContain('SUPER_ADMIN', 'ADMIN', 'ACCOUNTANT', 'VIEWER');

    /** @var array<string, bool> $holders */
    $holders = $response->json('groups.0.permissions.1.roles');
    /** @var list<array{permissions: list<mixed>}> $groups */
    $groups = $response->json('groups');

    expect($response->json('groups.0.group'))->toBe('platform')
        ->and($response->json('groups.0.permissions.1.permission'))->toBe('system.settings.manage')
        ->and($response->json('groups.0.permissions.1.enforced'))->toBeTrue()
        ->and($holders['SUPER_ADMIN'])->toBeTrue()
        ->and($holders['ADMIN'])->toBeFalse()
        // Counted by the server across every group, so the screen adds nothing.
        ->and($response->json('permission_count'))->toBe(array_sum(array_map(static fn (array $group): int => count($group['permissions']), $groups)));
});

it('marks permissions that nothing checks yet as unenforced', function (): void {
    $catalogue = app(AccessCatalogue::class);

    expect($catalogue->isEnforced('system.settings.manage'))->toBeTrue()
        ->and($catalogue->isEnforced('documents.decrypt'))->toBeFalse();
});

/*
 * `users.manage` is SUPER_ADMIN's alone as shipped, but it is a permission like any other. Given to
 * ADMIN, it opens both screens that set roles — and neither may then make anyone a super
 * administrator, or unmake one.
 */
function administratorWhoManagesUsers(): User
{
    Role::findByName('ADMIN')->givePermissionTo('users.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return userWithRole('ADMIN');
}

it('lets only a super administrator grant or remove the role on the roles screen', function (string $who, array $from, array $to): void {
    $actor = administratorWhoManagesUsers();
    $target = $who === 'self' ? $actor : User::factory()->create();
    $target->syncRoles($from);
    userWithRole('SUPER_ADMIN');

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $target->id . '/roles', ['roles' => $to])
        ->assertForbidden()
        ->assertJsonPath('reason', 'SUPER_ADMIN_ONLY');

    expect($target->fresh()?->getRoleNames()->all())->toBe($from);
})->with([
    'granting it to themselves' => ['self', ['ADMIN'], ['ADMIN', 'SUPER_ADMIN']],
    'granting it to someone else' => ['other', ['VIEWER'], ['SUPER_ADMIN']],
    'removing it from a super administrator' => ['other', ['SUPER_ADMIN'], ['VIEWER']],
]);

it('lets an administrator who manages users change every other role', function (): void {
    $target = User::factory()->create();

    $this->actingAs(administratorWhoManagesUsers())
        ->putJson('/api/v1/admin/users/' . $target->id . '/roles', ['roles' => ['ACCOUNTANT']])
        ->assertOk();

    expect($target->fresh()?->getRoleNames()->all())->toBe(['ACCOUNTANT']);
});

it('lets a super administrator grant and remove the role', function (): void {
    $actor = userWithRole('SUPER_ADMIN');
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $target->id . '/roles', ['roles' => ['SUPER_ADMIN']])
        ->assertOk();
    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $target->id . '/roles', ['roles' => ['VIEWER']])
        ->assertOk();

    expect($target->fresh()?->getRoleNames()->all())->toBe(['VIEWER']);
});

it('lets only a super administrator open an account as one', function (): void {
    $this->actingAs(administratorWhoManagesUsers())
        ->postJson('/api/v1/admin/users', ['email' => 'new-admin@example.test', 'roles' => ['SUPER_ADMIN']])
        ->assertForbidden()
        ->assertJsonPath('reason', 'SUPER_ADMIN_ONLY');

    $this->actingAs(userWithRole('SUPER_ADMIN'))
        ->postJson('/api/v1/admin/users', ['email' => 'new-admin@example.test', 'roles' => ['SUPER_ADMIN']])
        ->assertCreated();
});

it('opens accounts only with the roles config/access.php declares', function (): void {
    config()->set('access.roles', array_diff_key(Config::array('access.roles'), ['VIEWER' => true]));

    $this->actingAs(userWithRole('SUPER_ADMIN'))
        ->postJson('/api/v1/admin/users', ['email' => 'viewer@example.test', 'roles' => ['VIEWER']])
        ->assertUnprocessable();
});

it('lets only a super administrator change a super administrator\'s status', function (): void {
    // The dangerous shape: `users.manage` handed to ADMIN. Without the guard an ordinary administrator
    // could suspend the super administrators — suspension revokes their sessions — and lock the top
    // role out of the installation from below it.
    $actor = administratorWhoManagesUsers();
    $superAdmin = userWithRole('SUPER_ADMIN');

    $this->actingAs($actor)
        ->putJson('/api/v1/admin/users/' . $superAdmin->id . '/status', ['status' => 'SUSPENDED'])
        ->assertForbidden();

    expect($superAdmin->fresh()?->status)->toBe(UserStatus::ACTIVE);

    // A super administrator may, while another remains active.
    $target = userWithRole('SUPER_ADMIN');
    $this->actingAs(userWithRole('SUPER_ADMIN'))
        ->putJson('/api/v1/admin/users/' . $target->id . '/status', ['status' => 'SUSPENDED'])
        ->assertOk();
});
