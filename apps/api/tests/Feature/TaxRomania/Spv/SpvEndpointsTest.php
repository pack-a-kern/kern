<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\SpvEndpointsTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\TaxRomania\Spv\Enums\SpvSyncRunStatus;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSyncRun;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * The HTTP layer over the SPV module, which had none: every action existed and nothing could reach
 * them. These cover what the endpoints themselves decide — the rest is the actions' own tests.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/** @return array{0: User, 1: string} */
function connectedUser(bool $withMfa = true): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    if ($withMfa) {
        UserMfaMethod::create([
            'user_id' => $user->id,
            'type' => MfaMethodType::TOTP,
            'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
            'confirmed_at' => now('UTC'),
        ]);

        // Enrolled *and* recently proved: every route that acts is behind `verify-recent`, and these
        // tests are about what the endpoints decide once past it. The gate has its own file.
        verifySession();
    }

    return [$user, $subject->id];
}

it('registers a portal connection and lists it back', function (): void {
    [$user, $subjectId] = connectedUser();

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertCreated();

    // The username comes back decrypted: it is the taxpayer's own login name, which the sign-in form
    // shows them again. It is encrypted at rest because it identifies a person to ANAF, not because
    // it is a secret from the person it belongs to.
    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subjectId}/spv/connections")
        ->assertOk()
        ->assertJsonPath('connections.0.auth_method', 'USER_PASSWORD_OTP')
        ->assertJsonPath('connections.0.username', 'contribuabil.test')
        // The one case the column exists to make queryable rather than merely absent.
        ->assertJsonPath('connections.0.credential_policy', 'EPHEMERAL_ONLY')
        ->assertJsonPath('connections.0.status', 'PENDING');
});

it('does not hold up an account that has not turned on two-factor authentication', function (): void {
    [$user, $subjectId] = connectedUser(withMfa: false);

    // Turning TOTP on is what makes the gate exist for an account. Until then there is no factor to
    // challenge, and demanding enrolment in a dialog to get past a screen would be forcing a security
    // decision at the worst moment — the security screen is where that choice belongs.
    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertCreated();

    expect(SpvConnection::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('wants a username for the portal and none for a certificate', function (): void {
    [$user, $subjectId] = connectedUser();

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", ['auth_method' => 'USER_PASSWORD_OTP'])
        ->assertStatus(422);

    // A certificate carries the identity; a username beside it would be a second answer to the same
    // question, so it is accepted without one and stored as none.
    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'CERTIFICATE',
            'username' => 'ignored',
        ])
        ->assertCreated();

    $connection = SpvConnection::query()->withoutGlobalScopes()->sole();

    expect($connection->username_ciphertext)->toBeNull()
        ->and($connection->username_blind_index)->toBeNull();
});

it('does not serve one taxpayer another taxpayer’s connections', function (): void {
    [$owner, $ownerSubject] = connectedUser();
    [$stranger] = connectedUser();

    $this->actingAs($owner, 'web')
        ->postJson("/api/v1/subjects/{$ownerSubject}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertCreated();

    $this->actingAs($stranger, 'web')
        ->getJson("/api/v1/subjects/{$ownerSubject}/spv/connections")
        ->assertForbidden();
});

it('deletes a connection and the sync history that pointed at it', function (): void {
    [$user, $subjectId] = connectedUser();

    $created = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'CERTIFICATE',
        ])
        ->assertCreated();

    $connectionId = jsonString($created, 'id');

    SpvSyncRun::create([
        'tax_subject_id' => $subjectId,
        'connection_id' => $connectionId,
        'status' => SpvSyncRunStatus::SUCCEEDED,
        'started_at' => now('UTC'),
        'completed_at' => now('UTC'),
        'summary_json' => ['message_count' => 2],
    ]);

    $this->actingAs($user, 'web')
        ->deleteJson("/api/v1/subjects/{$subjectId}/spv/connections/{$connectionId}")
        ->assertNoContent();

    expect(SpvConnection::query()->find($connectionId))->toBeNull()
        // ON DELETE CASCADE, which is the cost the dialog states before anybody agrees to it.
        ->and(SpvSyncRun::query()->where('connection_id', $connectionId)->count())->toBe(0);
});

it('revokes without destroying what the connection did', function (): void {
    [$user, $subjectId] = connectedUser();

    $created = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'CERTIFICATE',
        ]);

    $connectionId = jsonString($created, 'id');

    SpvSyncRun::create([
        'tax_subject_id' => $subjectId,
        'connection_id' => $connectionId,
        'status' => SpvSyncRunStatus::SUCCEEDED,
        'started_at' => now('UTC'),
        'completed_at' => now('UTC'),
        'summary_json' => ['message_count' => 2],
    ]);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections/{$connectionId}/revocation")
        ->assertOk()
        ->assertJsonPath('status', 'REVOKED');

    expect(SpvSyncRun::query()->where('connection_id', $connectionId)->count())->toBe(1);
});

it('reports how many runs a connection has, so the screen knows which endings to offer', function (): void {
    [$user, $subjectId] = connectedUser();

    $created = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", ['auth_method' => 'CERTIFICATE']);

    $connectionId = jsonString($created, 'id');

    // Nothing has run: there is no history to preserve, so revoking has nothing to offer over
    // deleting, and the screen shows only the one ending.
    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subjectId}/spv/connections")
        ->assertJsonPath('connections.0.run_count', 0);

    SpvSyncRun::create([
        'tax_subject_id' => $subjectId,
        'connection_id' => $connectionId,
        'status' => SpvSyncRunStatus::SUCCEEDED,
        'started_at' => now('UTC'),
        'completed_at' => now('UTC'),
        'summary_json' => ['message_count' => 1],
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subjectId}/spv/connections")
        ->assertJsonPath('connections.0.run_count', 1);
});
