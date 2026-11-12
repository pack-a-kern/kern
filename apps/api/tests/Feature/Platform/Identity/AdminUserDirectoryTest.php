<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `$response->json(...)` is `mixed`; this narrows one user row once so each assertion reads
 * plainly and PHPStan has something concrete to check.
 *
 * @return array<string, mixed>
 */
function directoryRow(Tests\TestCase $test, User $admin): array
{
    /** @var list<array<string, mixed>> $users */
    $users = $test->actingAs($admin)->getJson('/api/v1/admin/users')->json('users');

    foreach ($users as $row) {
        if (($row['id'] ?? null) === $admin->getKey()) {
            return $row;
        }
    }

    return [];
}

it('lists accounts with their MFA posture and memberships', function (): void {
    $admin = settingsAdminUser();

    $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk();

    $row = directoryRow($this, $admin);

    expect($row)->not->toBe([])
        ->and($row['status'])->toBe('ACTIVE')
        ->and($row['passkeys'])->toBe(0)
        ->and($row['totp'])->toBeFalse()
        ->and($row['memberships'])->toBe([]);
});

it('lists the memberships in force, not the ones revoked', function (): void {
    $admin = settingsAdminUser();

    foreach ([[MembershipRole::OWNER, null], [MembershipRole::VIEWER, now('UTC')]] as [$role, $revokedAt]) {
        SubjectMembership::create([
            'subject_id' => TaxSubject::factory()->create()->id,
            'user_id' => $admin->id,
            'role' => $role,
            'permissions_json' => [],
            'valid_from' => now('UTC'),
            'revoked_at' => $revokedAt,
        ]);
    }

    expect(directoryRow($this, $admin)['memberships'])->toBe([MembershipRole::OWNER->value]);
});

it('decrypts the email so the directory is usable, and returns no other encrypted field', function (): void {
    $admin = settingsAdminUser();

    $row = directoryRow($this, $admin);

    expect($row['email'])->toBe($admin->email_ciphertext)
        ->and($row['email'])->toContain('@')
        ->and($row)->not->toHaveKey('password_hash')
        ->and($row)->not->toHaveKey('email_blind_index');
});

it('counts only confirmed TOTP methods', function (): void {
    $admin = settingsAdminUser();

    // An unconfirmed method is a setup that was started and abandoned; reporting it as MFA
    // coverage would overstate the security posture of the installation.
    // Inserted through the query builder on purpose. `UserMfaMethod`'s encrypted cast derives its
    // AAD from `user_id`, which a relation-`create()` only sets after the attribute is assigned —
    // and this test is about the `confirmed_at` filter, not about the crypto path.
    DB::table('user_mfa_methods')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $admin->getKey(),
        'type' => 'TOTP',
        'secret_ciphertext' => 'not-a-real-secret',
        'confirmed_at' => null,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    expect(directoryRow($this, $admin)['totp'])->toBeFalse();
});

it('reports every membership role, including those with no members', function (): void {
    $response = $this->actingAs(settingsAdminUser())->getJson('/api/v1/admin/users')->assertOk();

    /** @var list<array{role: string, members: int}> $roles */
    $roles = $response->json('roles');

    expect(array_column($roles, 'role'))
        ->toBe(array_map(static fn (MembershipRole $role): string => $role->value, MembershipRole::cases()));
});

it('states that per-subject membership roles are not enforced, because they are not', function (): void {
    // Platform roles *are* enforced (see PlatformPermissionsTest); `MembershipRole` still is not.
    // If per-subject checks are ever implemented, this test fails and whoever implemented them has
    // to update the flag and the screen's copy.
    $response = $this->actingAs(settingsAdminUser())->getJson('/api/v1/admin/users')->assertOk();

    expect($response->json('enforcement.membership_roles_enforced'))->toBeFalse();
});

it('reports the platform role a user actually holds', function (): void {
    $admin = settingsAdminUser();

    expect(directoryRow($this, $admin)['roles'])->toBe(['SUPER_ADMIN']);
});

it('is closed to a user who is not a settings administrator', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/admin/users')->assertForbidden();
});

it('finds an account by its whole address, never by part of one', function (): void {
    $admin = settingsAdminUser();
    $wanted = User::factory()->withEmail('wanted@example.test')->create();
    User::factory()->create();

    $whole = $this->actingAs($admin)->getJson('/api/v1/admin/users?email=' . urlencode(' Wanted@Example.test '))->assertOk();
    $part = $this->actingAs($admin)->getJson('/api/v1/admin/users?email=wanted')->assertOk();

    expect(array_column((array) $whole->json('users'), 'id'))->toBe([$wanted->id])
        ->and($whole->json('total'))->toBe(1)
        ->and($part->json('users'))->toBe([])
        ->and($part->json('total'))->toBe(0);
});

it('narrows by status and by platform role on the server', function (): void {
    $admin = settingsAdminUser();
    $suspended = User::factory()->create(['status' => UserStatus::SUSPENDED]);

    $byStatus = $this->actingAs($admin)->getJson('/api/v1/admin/users?status=SUSPENDED')->assertOk();
    $byRole = $this->actingAs($admin)->getJson('/api/v1/admin/users?role=SUPER_ADMIN')->assertOk();

    expect(array_column((array) $byStatus->json('users'), 'id'))->toBe([$suspended->id])
        ->and(array_column((array) $byRole->json('users'), 'id'))->toBe([$admin->id]);
});

it('pages the accounts and counts over all of them, whatever the page holds', function (): void {
    $admin = settingsAdminUser();
    User::factory()->count(11)->create();

    $second = $this->actingAs($admin)->getJson('/api/v1/admin/users?page=2&per_page=10')->assertOk();
    $pastTheEnd = $this->actingAs($admin)->getJson('/api/v1/admin/users?page=9&per_page=10')->assertOk();
    $filtered = $this->actingAs($admin)->getJson('/api/v1/admin/users?status=SUSPENDED')->assertOk();

    expect($second->json('users'))->toHaveCount(2)
        ->and($second->json('total'))->toBe(12)
        ->and($pastTheEnd->json('page'))->toBe(2)
        ->and($filtered->json('total'))->toBe(0)
        ->and($filtered->json('stats.users'))->toBe(12)
        ->and($filtered->json('stats.with_mfa'))->toBe(0)
        ->and($filtered->json('stats.roles'))->toBe(['SUPER_ADMIN' => 1]);
});

it('refuses a role, status or page size it does not know', function (string $query): void {
    $this->actingAs(settingsAdminUser())->getJson('/api/v1/admin/users?' . $query)->assertUnprocessable();
})->with(['role=NOBODY', 'status=GONE', 'per_page=7', 'page=0']);
