<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\SpvEndpointsTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\TaxRomania\Spv\DataTransferObjects\PortalAuthResult;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Enums\SpvSyncRunStatus;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;
use Kern\TaxRomania\Spv\Models\SpvSyncRun;
use Kern\TaxRomania\Spv\Services\ApplyPortalAuthResult;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * The HTTP layer over the SPV module, which had none: every action existed and nothing could reach
 * them. These cover what the endpoints themselves decide — the rest is the actions' own tests.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
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

it('keeps what the portal said, so a person is told which credential was wrong', function (): void {
    [$user, $subjectId] = connectedUser();

    $created = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ]);

    $connectionId = jsonString($created, 'id');
    /** @var SpvConnection $connection */
    $connection = SpvConnection::query()->findOrFail($connectionId);

    $session = SpvSession::create([
        'tax_subject_id' => $subjectId,
        'connection_id' => $connectionId,
        'state' => SpvSessionState::CREATED,
        'browser_session_ref' => 'worker-session-err',
        'expires_at' => now('UTC')->addMinutes(5),
    ]);

    // Exactly what ANAF prints in `#credentials_table_postheader`, observed 2026-10-06.
    $portalSaid = 'Utilizatorul sau parola nu sunt corecte. Va rugam să incercati din nou.';

    app(ApplyPortalAuthResult::class)($session, $connection, new PortalAuthResult(SpvSessionState::FAILED, $portalSaid));

    $connection->refresh();

    expect($connection->last_error_message)->toBe($portalSaid)
        // The code stays a code. It is ours, it is closed, and it is what the dictionary translates;
        // the sentence above is ANAF's and is quoted rather than rendered.
        ->and($connection->last_error_code)->toBe('authentication_failed');

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subjectId}/spv/connections")
        ->assertJsonPath('connections.0.last_error_message', $portalSaid);
});

it('does not let a portal message outgrow its column', function (): void {
    // `varchar(255)` is what this used to share with the error code: a portal free to print a paragraph
    // would have thrown on Postgres and been truncated nowhere on sqlite, so the suite would have gone
    // green on one lane and red on the other.
    [$user, $subjectId] = connectedUser();

    $created = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", ['auth_method' => 'CERTIFICATE']);

    /** @var SpvConnection $connection */
    $connection = SpvConnection::query()->findOrFail(jsonString($created, 'id'));

    $session = SpvSession::create([
        'tax_subject_id' => $subjectId,
        'connection_id' => $connection->id,
        'state' => SpvSessionState::CREATED,
        'browser_session_ref' => 'worker-session-long',
        'expires_at' => now('UTC')->addMinutes(5),
    ]);

    app(ApplyPortalAuthResult::class)(
        $session,
        $connection,
        new PortalAuthResult(SpvSessionState::FAILED, str_repeat('ă', 4000)),
    );

    $connection->refresh();

    expect(mb_strlen((string) $connection->last_error_message))->toBe(500);
});

it('tells the screen whether a signed-in browser is open, which is what gates reading the inbox', function (): void {
    [$user, $subjectId] = connectedUser();

    $created = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ]);

    $connectionId = jsonString($created, 'id');

    // Nothing open yet: the action is not offered, because there is no browser to read the portal in.
    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subjectId}/spv/connections")
        ->assertJsonPath('connections.0.session', null);

    $session = SpvSession::create([
        'tax_subject_id' => $subjectId,
        'connection_id' => $connectionId,
        'state' => SpvSessionState::AUTHENTICATED,
        'browser_session_ref' => 'worker-live',
        'expires_at' => now('UTC')->addMinutes(10),
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subjectId}/spv/connections")
        ->assertJsonPath('connections.0.session.expires_at', $session->expires_at->toAtomString());

    // Past its expiry the worker has dropped the browser without telling anyone, so the row stops
    // counting as live here rather than the screen comparing timestamps for ever in an open tab.
    $session->expires_at = now('UTC')->subMinute();
    $session->save();

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subjectId}/spv/connections")
        ->assertJsonPath('connections.0.session', null);
});

it('answers a second sign-in on a signed-in connection with a reason the screen can say', function (): void {
    [$user, $subjectId] = connectedUser();
    $connectionId = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", ['auth_method' => 'USER_PASSWORD_OTP', 'username' => 'contribuabil.test'])
        ->assertCreated()
        ->json('id');
    assert(is_string($connectionId));

    SpvSession::query()->withoutGlobalScopes()->create([
        'tax_subject_id' => $subjectId,
        'connection_id' => $connectionId,
        'browser_session_ref' => 'signed-in',
        'state' => SpvSessionState::AUTHENTICATED,
        'created_at' => now('UTC'),
        'expires_at' => now('UTC')->addMinutes(15),
    ]);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections/{$connectionId}/session")
        ->assertStatus(409)
        ->assertJsonPath('reason', 'SPV_ALREADY_SIGNED_IN');
});

it('answers a full worker as busy, with a reason the screen can say', function (): void {
    \Illuminate\Support\Facades\Http::fake(['*/v1/sessions' => \Illuminate\Support\Facades\Http::response(['error' => 'at_capacity'], 503)]);

    [$user, $subjectId] = connectedUser();
    $connectionId = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", ['auth_method' => 'USER_PASSWORD_OTP', 'username' => 'contribuabil.test'])
        ->assertCreated()
        ->json('id');
    assert(is_string($connectionId));

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections/{$connectionId}/session")
        ->assertStatus(503)
        ->assertJsonPath('reason', 'SPV_WORKER_BUSY');
});
