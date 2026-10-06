<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\InboxSyncTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kern\Audit\Models\AuditEvent;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Spv\Actions\SyncSpvInbox;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvConnectionStatus;
use Kern\TaxRomania\Spv\Enums\SpvCredentialPolicy;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Enums\SpvSyncRunStatus;
use Kern\TaxRomania\Spv\Events\SpvSyncCompleted;
use Kern\TaxRomania\Spv\Events\SpvSyncStarted;
use Kern\TaxRomania\Spv\Exceptions\SpvSignInRequiredException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvMessage;
use Kern\TaxRomania\Spv\Models\SpvSession;
use Kern\TaxRomania\Spv\Models\SpvSyncRun;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `docs/reference/anaf-spv-integration.md`'s two-transport inbox sync — `Kern\TaxRomania\Spv\Actions\
 * SyncSpvInbox` selects one by `SpvConnection::$auth_method` and never conflates the two.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
    config(['spv.anaf_api.client_certificate_path' => '/etc/spv/certs/fixture.p12']);
    config(['spv.anaf_api.client_certificate_password' => 'fixture-password']);
    config(['spv.browser_worker.auth_token' => 'test-worker-secret']);
});

/** See `tests/Feature/TaxRomania/Spv/CredentialFlowTest.php`'s identical helper docblock. */
function activateSpvSubject(TaxSubject $subject): void
{
    $user = User::factory()->create();
    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);
}

it('syncs via the documented ANAF certificate API for a CERTIFICATE connection, idempotently', function (): void {
    Event::fake([SpvSyncStarted::class, SpvSyncCompleted::class]);
    Http::fake([
        '*/listaMesaje*' => Http::response([
            'titlu' => 'Mesaje',
            'mesaje' => [
                ['id' => 'anaf-1', 'detalii' => 'Decizie de impunere', 'cif' => '123', 'data_creare' => '2026-01-15T10:00:00+00:00', 'id_solicitare' => 'req-1', 'tip' => 'DECIZIE'],
            ],
        ]),
    ]);

    $subject = TaxSubject::factory()->create();
    activateSpvSubject($subject);
    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::CERTIFICATE,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'status' => SpvConnectionStatus::PENDING,
    ]);

    $run = app(SyncSpvInbox::class)($connection->id, null);

    expect($run->status)->toBe(SpvSyncRunStatus::SUCCEEDED)
        ->and($run->summary_json)->toBe(['message_count' => 1])
        ->and(SpvMessage::query()->where('tax_subject_id', $subject->id)->count())->toBe(1);

    Event::assertDispatched(SpvSyncStarted::class);
    Event::assertDispatched(SpvSyncCompleted::class, fn (SpvSyncCompleted $event): bool => $event->status === SpvSyncRunStatus::SUCCEEDED && $event->messageCount === 1);

    // A second sync run seeing the same ANAF message id must not create a second spv_messages row.
    app(SyncSpvInbox::class)($connection->id, null);
    expect(SpvMessage::query()->where('tax_subject_id', $subject->id)->count())->toBe(1);

    expect(AuditEvent::query()->where('resource_id', $run->id)->where('event_type', 'SpvSyncStarted')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('resource_id', $run->id)->where('event_type', 'SpvSyncCompleted')->exists())->toBeTrue();
});

it('records a FAILED run when ANAF returns an error-shaped 200 response', function (): void {
    Http::fake(['*/listaMesaje*' => Http::response(['titlu' => 'Eroare', 'eroare' => 'certificat invalid'])]);

    $subject = TaxSubject::factory()->create();
    activateSpvSubject($subject);
    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::CERTIFICATE,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'status' => SpvConnectionStatus::PENDING,
    ]);

    $run = app(SyncSpvInbox::class)($connection->id, null);

    expect($run->status)->toBe(SpvSyncRunStatus::FAILED)
        ->and($run->summary_json)->toBe(['error' => 'unavailable']);
});

it('fails closed with no configured certificate, never attempting an unauthenticated ANAF call', function (): void {
    config(['spv.anaf_api.client_certificate_path' => null]);
    Http::fake();

    $subject = TaxSubject::factory()->create();
    activateSpvSubject($subject);
    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::CERTIFICATE,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'status' => SpvConnectionStatus::PENDING,
    ]);

    $run = app(SyncSpvInbox::class)($connection->id, null);

    expect($run->status)->toBe(SpvSyncRunStatus::FAILED);

    Http::assertNothingSent();
});

it('refuses to read the inbox of a password connection nobody has signed in', function (): void {
    // It used to open a *new* browser here and read the inbox in it. A new browser is not signed in, so
    // the playbook found a login page and extracted nothing — which no selector could have fixed. The
    // login lives in a running browser, so either there is one or the person has to make one.
    Http::fake(['*' => Http::response([], 200)]);

    $subject = TaxSubject::factory()->create();
    activateSpvSubject($subject);

    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::USER_PASSWORD_OTP,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'username_ciphertext' => 'owner@example.test',
        'status' => SpvConnectionStatus::PENDING,
    ]);

    expect(fn () => app(SyncSpvInbox::class)($connection->id, null))
        ->toThrow(SpvSignInRequiredException::class);

    // Recorded even so: the attempt happened and the history says why it stopped.
    expect(SpvSyncRun::query()->where('connection_id', $connection->id)->first()?->status)
        ->toBe(SpvSyncRunStatus::FAILED);

    // Nothing was asked of the worker at all: there was no session to ask about, and opening one would
    // have been the bug.
    Http::assertNothingSent();
});

it('reads the inbox in the browser the sign-in left open, rather than opening another', function (): void {
    Http::fake([
        '*/run' => Http::response([
            'outcome' => 'signed_in',
            'data' => ['messages' => [[
                'id' => '99',
                'detalii' => 'Decizie de impunere',
                'cif' => '1234567890',
                'data_creare' => '2026-03-01 09:30:00',
            ]]],
        ]),
    ]);

    $subject = TaxSubject::factory()->create();
    activateSpvSubject($subject);

    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::USER_PASSWORD_OTP,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'username_ciphertext' => 'owner@example.test',
        'status' => SpvConnectionStatus::ACTIVE,
    ]);

    SpvSession::create([
        'tax_subject_id' => $subject->id,
        'connection_id' => $connection->id,
        'state' => SpvSessionState::AUTHENTICATED,
        'browser_session_ref' => 'worker-signed-in',
        'expires_at' => now('UTC')->addMinutes(10),
    ]);

    $run = app(SyncSpvInbox::class)($connection->id, null);

    expect($run->status)->toBe(SpvSyncRunStatus::SUCCEEDED);

    // The session the sign-in opened, by name. No `POST /v1/sessions`, and no DELETE afterwards: the
    // login cost somebody reading an email, and tearing it down would make the next read cost another.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/sessions/worker-signed-in/run'));
    Http::assertSentCount(1);
});

it('asks the person to sign in again when the portal has let the session go', function (): void {
    // The browser is still open on this side and signed out on ANAF's. The playbook sees its login form
    // and says so, which is a different thing from the inbox being empty.
    Http::fake(['*/run' => Http::response(['outcome' => 'session_lost', 'data' => []])]);

    $subject = TaxSubject::factory()->create();
    activateSpvSubject($subject);

    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::USER_PASSWORD_OTP,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'username_ciphertext' => 'owner@example.test',
        'status' => SpvConnectionStatus::ACTIVE,
    ]);

    SpvSession::create([
        'tax_subject_id' => $subject->id,
        'connection_id' => $connection->id,
        'state' => SpvSessionState::AUTHENTICATED,
        'browser_session_ref' => 'worker-stale',
        'expires_at' => now('UTC')->addMinutes(10),
    ]);

    expect(fn () => app(SyncSpvInbox::class)($connection->id, null))
        ->toThrow(SpvSignInRequiredException::class);
});

it('will not read the inbox through a session the worker has already expired', function (): void {
    // `expires_at` is in the past, so the browser is gone whatever the row says. Checked here rather
    // than discovered by asking the worker about a session it no longer has.
    Http::fake(['*' => Http::response([], 200)]);

    $subject = TaxSubject::factory()->create();
    activateSpvSubject($subject);

    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::USER_PASSWORD_OTP,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'username_ciphertext' => 'owner@example.test',
        'status' => SpvConnectionStatus::ACTIVE,
    ]);

    SpvSession::create([
        'tax_subject_id' => $subject->id,
        'connection_id' => $connection->id,
        'state' => SpvSessionState::AUTHENTICATED,
        'browser_session_ref' => 'worker-expired',
        'expires_at' => now('UTC')->subMinute(),
    ]);

    expect(fn () => app(SyncSpvInbox::class)($connection->id, null))
        ->toThrow(SpvSignInRequiredException::class);

    Http::assertNothingSent();
});
