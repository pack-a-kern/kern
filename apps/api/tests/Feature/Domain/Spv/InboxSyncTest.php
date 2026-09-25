<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Spv\InboxSyncTest;

use App\Domain\Spv\Actions\SyncSpvInbox;
use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Enums\SpvConnectionStatus;
use App\Domain\Spv\Enums\SpvCredentialPolicy;
use App\Domain\Spv\Enums\SpvSyncRunStatus;
use App\Domain\Spv\Events\SpvSyncCompleted;
use App\Domain\Spv\Events\SpvSyncStarted;
use App\Domain\Spv\Models\SpvConnection;
use App\Domain\Spv\Models\SpvMessage;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kern\Audit\Models\AuditEvent;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;

/**
 * `docs/reference/anaf-spv-integration.md`'s two-transport inbox sync — `App\Domain\Spv\Actions\
 * SyncSpvInbox` selects one by `SpvConnection::$auth_method` and never conflates the two.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
    config(['spv.anaf_api.client_certificate_path' => '/etc/spv/certs/fixture.p12']);
    config(['spv.anaf_api.client_certificate_password' => 'fixture-password']);
    config(['spv.browser_worker.auth_token' => 'test-worker-secret']);
});

/** See `tests/Feature/Domain/Spv/CredentialFlowTest.php`'s identical helper docblock. */
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

it('routes a USER_PASSWORD_OTP connection through the browser transport, which fails today with no observed portal', function (): void {
    Http::fake([
        '*/v1/spv/sessions' => Http::response(['id' => 'worker-sync-session', 'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String()], 201),
        '*/v1/spv/sessions/worker-sync-session/actions/sync' => Http::response(['error' => 'not_implemented', 'action' => 'sync', 'message' => 'nope'], 501),
        '*/v1/spv/sessions/worker-sync-session' => Http::response(null, 204),
    ]);

    $subject = TaxSubject::factory()->create();
    activateSpvSubject($subject);
    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::USER_PASSWORD_OTP,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'username_ciphertext' => 'owner@example.test',
        'status' => SpvConnectionStatus::PENDING,
    ]);

    $run = app(SyncSpvInbox::class)($connection->id, null);

    expect($run->status)->toBe(SpvSyncRunStatus::FAILED);

    // The browser session opened for the sync attempt is torn down even though the sync itself
    // failed — the worker resource is never leaked.
    Http::assertSentCount(3);
});
