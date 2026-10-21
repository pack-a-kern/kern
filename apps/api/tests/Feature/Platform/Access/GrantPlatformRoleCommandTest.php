<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Kern\Platform\Access\SuperAdministratorRole;
use Kern\Platform\Identity\Actions\EraseUser;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;

uses(RefreshDatabase::class);

/*
 * The shell is the way back in when nobody can grant roles through the screen, so it is also where
 * the last super administrator is protected: revoking that one asks first.
 */

beforeEach(function (): void {
    Artisan::call('kern:access:sync');
});

it('asks before revoking the last super administrator, and keeps it on a no', function (): void {
    $last = User::factory()->withEmail('last-admin@example.test')->create();
    $last->assignRole(SuperAdministratorRole::NAME);

    $run = $this->artisan('kern:access:grant', ['email' => 'last-admin@example.test', 'role' => SuperAdministratorRole::NAME, '--revoke' => true]);

    /** @var PendingCommand $run */
    $run->expectsConfirmation('Revoke anyway?', 'no')->assertFailed()->run();

    expect(SuperAdministratorRole::isHeldBy($last->refresh()))->toBeTrue();
});

it('revokes a super administrator without asking while another remains', function (): void {
    User::factory()->create()->assignRole(SuperAdministratorRole::NAME);
    $leaving = User::factory()->withEmail('leaving-admin@example.test')->create();
    $leaving->assignRole(SuperAdministratorRole::NAME);

    $run = $this->artisan('kern:access:grant', ['email' => 'leaving-admin@example.test', 'role' => SuperAdministratorRole::NAME, '--revoke' => true]);

    /** @var PendingCommand $run */
    $run->assertSuccessful()->run();

    expect(SuperAdministratorRole::isHeldBy($leaving->refresh()))->toBeFalse();
});

it('counts only active super administrators as somebody left', function (string $status): void {
    // A suspended account cannot sign in to grant anything, and an erased one never will. Holding the
    // role, either used to count as "another super administrator", and the last real one went unasked.
    $other = User::factory()->create(['status' => $status]);
    $other->assignRole(SuperAdministratorRole::NAME);
    $last = User::factory()->withEmail('last-active-admin@example.test')->create();
    $last->assignRole(SuperAdministratorRole::NAME);

    $run = $this->artisan('kern:access:grant', ['email' => 'last-active-admin@example.test', 'role' => SuperAdministratorRole::NAME, '--revoke' => true]);

    /** @var PendingCommand $run */
    $run->expectsConfirmation('Revoke anyway?', 'no')->assertFailed()->run();

    expect(SuperAdministratorRole::isHeldBy($last->refresh()))->toBeTrue();
})->with(['suspended' => [UserStatus::SUSPENDED->value], 'erased' => [UserStatus::DELETED->value]]);

it('takes the roles off accounts erased before erasure did so itself', function (): void {
    $tombstone = User::factory()->create(['status' => UserStatus::DELETED->value]);
    $tombstone->assignRole(SuperAdministratorRole::NAME);
    $active = User::factory()->create();
    $active->assignRole(SuperAdministratorRole::NAME);

    $migration = require packageRoot(EraseUser::class) . '/database/migrations/2026_10_21_100000_remove_platform_roles_from_erased_accounts.php';
    assert($migration instanceof Migration && method_exists($migration, 'up'));
    $migration->up();

    expect($tombstone->refresh()->getRoleNames()->all())->toBe([])
        ->and(SuperAdministratorRole::isHeldBy($active->refresh()))->toBeTrue();
});
