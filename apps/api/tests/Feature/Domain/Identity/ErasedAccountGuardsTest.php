<?php

declare(strict_types=1);

use Kern\Platform\Identity\Actions\EraseUser;
use Kern\Platform\Identity\Models\User;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

/**
 * An account that has been through `EraseUser`.
 *
 * Erased through the action rather than by setting the column, so these tests run against the same
 * tombstone the screen produces — no password, no second factor, status `DELETED`.
 */
function erasedUser(User $administrator): User
{
    $target = User::factory()->create();

    app(EraseUser::class)($target, $administrator->id);

    return $target->refresh();
}

/*
 * Erasure is the one state with no way back, so every route that changes an account has to refuse
 * a tombstone. The screen hides the row menu on a DELETED user, but that is a courtesy: these
 * four are what actually stops a stale tab, a replayed request, or a direct call.
 */

it('refuses to reactivate an erased account', function (): void {
    $administrator = settingsAdminUser();
    $erased = erasedUser($administrator);

    $this->actingAs($administrator)
        ->putJson("/api/v1/admin/users/{$erased->id}/status", ['status' => 'ACTIVE'])
        ->assertStatus(422);

    expect($erased->refresh()->status->value)->toBe('DELETED');
});

it('refuses to change the roles of an erased account', function (): void {
    $administrator = settingsAdminUser();
    $erased = erasedUser($administrator);

    $this->actingAs($administrator)
        ->putJson("/api/v1/admin/users/{$erased->id}/roles", ['roles' => ['SUPER_ADMIN']])
        ->assertStatus(422);

    expect($erased->refresh()->getRoleNames()->all())->toBe([]);
});

it('refuses to erase an account twice', function (): void {
    $administrator = settingsAdminUser();
    $erased = erasedUser($administrator);

    $this->actingAs($administrator)
        ->deleteJson("/api/v1/admin/users/{$erased->id}")
        ->assertStatus(422);
});

it('refuses to act as an erased account', function (): void {
    $administrator = settingsAdminUser();
    $erased = erasedUser($administrator);

    $this->actingAs($administrator)
        ->postJson("/api/v1/admin/users/{$erased->id}/impersonate")
        ->assertStatus(422);
});
