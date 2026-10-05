<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\SpvEndpointsTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\TaxRomania\Spv\Models\SpvConnection;
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

it('refuses to register a connection for a user with no MFA', function (): void {
    [$user, $subjectId] = connectedUser(withMfa: false);

    // §4.7 — MFA is obligatory for an account that connects SPV. Enforced in the action, so it holds
    // for every caller; a refusal rather than a fault, because the person has to go and enrol and a
    // 500 would tell them the installation is broken.
    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertForbidden()
        ->assertJsonPath('reason', 'MFA_REQUIRED');

    expect(SpvConnection::query()->withoutGlobalScopes()->count())->toBe(0);
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
