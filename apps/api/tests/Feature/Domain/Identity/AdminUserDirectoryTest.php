<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

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
