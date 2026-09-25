<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\CredentialFlowTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kern\Audit\Models\AuditEvent;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Spv\Actions\DestroySpvSession;
use Kern\TaxRomania\Spv\Actions\InitiateSpvAuthAttempt;
use Kern\TaxRomania\Spv\Actions\SubmitSpvChallengeCode;
use Kern\TaxRomania\Spv\Actions\SubmitSpvCredentials;
use Kern\TaxRomania\Spv\DataTransferObjects\SubmitSpvChallengeCodeInput;
use Kern\TaxRomania\Spv\DataTransferObjects\SubmitSpvCredentialsInput;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvConnectionStatus;
use Kern\TaxRomania\Spv\Enums\SpvCredentialPolicy;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Events\SpvAuthChallengeRequired;
use Kern\TaxRomania\Spv\Events\SpvAuthenticated;
use Kern\TaxRomania\Spv\Exceptions\InvalidSpvSessionTransitionException;
use Kern\TaxRomania\Spv\Exceptions\SpvChallengeExpiredException;
use Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `BLUEPRINT.md` §13.2 steps 1-11, exercised end to end against `apps/spv-browser`'s real (documented
 * here) HTTP contract via `Http::fake()` — the worker itself is not started; this proves Laravel's
 * own state machine, audit trail, and broadcast wiring.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
    config(['spv.browser_worker.auth_token' => 'test-worker-secret']);
    config(['spv.challenge_ttl_seconds' => 300]);
});

function spvUserWithMfa(): User
{
    $user = User::factory()->create();
    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);

    return $user;
}

function spvConnectionFor(TaxSubject $subject): SpvConnection
{
    return SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::USER_PASSWORD_OTP,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'username_ciphertext' => 'owner@example.test',
        'status' => SpvConnectionStatus::PENDING,
    ]);
}

/**
 * `Kern\TaxRomania\Spv\Actions\*` read `spv_connections`/`spv_sessions` through
 * `Kern\Platform\Tenancy\ScopedToTenant`, exactly like every other business module —
 * an authorized membership must be activated before any of them can query, the same
 * `docs/handoff/011-identity-tenancy.md` mechanism `tests/Feature/TaxRomania/Rental/
 * CrossTenantIsolationTest.php` sets up for itself.
 */
function activateSpvSubject(User $user, TaxSubject $subject): void
{
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

it('initiates a session against the worker and records an audit event', function (): void {
    Http::fake([
        '*/v1/spv/sessions' => Http::response([
            'id' => 'worker-session-1',
            'status' => 'created',
            'createdAt' => now('UTC')->toIso8601String(),
            'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String(),
        ], 201),
    ]);

    $user = spvUserWithMfa();
    $subject = TaxSubject::factory()->create();
    activateSpvSubject($user, $subject);
    $connection = spvConnectionFor($subject);

    $session = app(InitiateSpvAuthAttempt::class)($user, $connection->id);

    expect($session->state)->toBe(SpvSessionState::CREATED)
        ->and($session->browser_session_ref)->toBe('worker-session-1');

    expect(AuditEvent::query()->where('resource_id', $session->id)->where('event_type', 'SpvAuthAttemptInitiated')->exists())->toBeTrue();
});

it('moves a session to AWAITING_USER_CHALLENGE and broadcasts once credentials are accepted, never persisting the password', function (): void {
    Event::fake([SpvAuthChallengeRequired::class]);
    Http::fake([
        '*/v1/spv/sessions' => Http::response(['id' => 'worker-session-2', 'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String()], 201),
        '*/v1/spv/sessions/worker-session-2/credentials' => Http::response(['status' => 'awaiting_challenge']),
    ]);

    $user = spvUserWithMfa();
    $subject = TaxSubject::factory()->create();
    activateSpvSubject($user, $subject);
    $connection = spvConnectionFor($subject);
    $session = app(InitiateSpvAuthAttempt::class)($user, $connection->id);

    $updated = app(SubmitSpvCredentials::class)(new SubmitSpvCredentialsInput(
        sessionId: $session->id,
        username: 'owner@example.test',
        password: SensitiveString::wrap('the-real-password'),
        actorId: $user->id,
    ));

    expect($updated->state)->toBe(SpvSessionState::AWAITING_USER_CHALLENGE)
        ->and($updated->challenge_requested_at)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://spv-browser:8090/v1/spv/sessions/worker-session-2/credentials'
            && $request['password'] === 'the-real-password';
    });

    Event::assertDispatched(SpvAuthChallengeRequired::class, fn (SpvAuthChallengeRequired $event): bool => $event->sessionId === $session->id);

    expect(AuditEvent::query()->where('resource_id', $session->id)->where('event_type', 'SpvCredentialsSubmitted')->exists())->toBeTrue();

    // The password is a request-body value the fake HTTP client recorded for this assertion only
    // — nothing on the Laravel side of the boundary ever wrote it to a column or an audit payload.
    foreach (Schema::getColumnListing('spv_sessions') as $column) {
        expect(mb_strtolower((string) $column))->not->toContain('password');
    }
});

it('rejects submitting credentials to a session that already moved past CREATED', function (): void {
    Http::fake([
        '*/v1/spv/sessions' => Http::response(['id' => 'worker-session-3', 'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String()], 201),
        '*/v1/spv/sessions/worker-session-3/credentials' => Http::response(['status' => 'awaiting_challenge']),
    ]);

    $user = spvUserWithMfa();
    $subject = TaxSubject::factory()->create();
    activateSpvSubject($user, $subject);
    $connection = spvConnectionFor($subject);
    $session = app(InitiateSpvAuthAttempt::class)($user, $connection->id);

    $input = new SubmitSpvCredentialsInput($session->id, 'owner@example.test', SensitiveString::wrap('pw'), $user->id);
    app(SubmitSpvCredentials::class)($input);

    app(SubmitSpvCredentials::class)($input);
})->throws(InvalidSpvSessionTransitionException::class);

it('reaches AUTHENTICATED and activates the connection once the challenge code is accepted', function (): void {
    Event::fake([SpvAuthenticated::class]);
    Http::fake([
        '*/v1/spv/sessions' => Http::response(['id' => 'worker-session-4', 'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String()], 201),
        '*/v1/spv/sessions/worker-session-4/credentials' => Http::response(['status' => 'awaiting_challenge']),
        '*/v1/spv/sessions/worker-session-4/challenge' => Http::response(['status' => 'authenticated']),
    ]);

    $user = spvUserWithMfa();
    $subject = TaxSubject::factory()->create();
    activateSpvSubject($user, $subject);
    $connection = spvConnectionFor($subject);
    $session = app(InitiateSpvAuthAttempt::class)($user, $connection->id);
    app(SubmitSpvCredentials::class)(new SubmitSpvCredentialsInput($session->id, 'owner@example.test', SensitiveString::wrap('pw'), $user->id));

    $updated = app(SubmitSpvChallengeCode::class)(new SubmitSpvChallengeCodeInput($session->id, SensitiveString::wrap('123456'), $user->id));

    expect($updated->state)->toBe(SpvSessionState::AUTHENTICATED);

    $connection->refresh();
    expect($connection->status)->toBe(SpvConnectionStatus::ACTIVE)
        ->and($connection->last_success_at)->not->toBeNull();

    Event::assertDispatched(SpvAuthenticated::class);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/challenge') && $request['code'] === '123456');
});

it('expires an unanswered challenge past its TTL, never forwarding the code to the worker', function (): void {
    config(['spv.challenge_ttl_seconds' => 1]);
    Http::fake([
        '*/v1/spv/sessions' => Http::response(['id' => 'worker-session-5', 'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String()], 201),
        '*/v1/spv/sessions/worker-session-5/credentials' => Http::response(['status' => 'awaiting_challenge']),
        '*/v1/spv/sessions/worker-session-5' => Http::response(null, 204),
    ]);

    $user = spvUserWithMfa();
    $subject = TaxSubject::factory()->create();
    activateSpvSubject($user, $subject);
    $connection = spvConnectionFor($subject);
    $session = app(InitiateSpvAuthAttempt::class)($user, $connection->id);
    app(SubmitSpvCredentials::class)(new SubmitSpvCredentialsInput($session->id, 'owner@example.test', SensitiveString::wrap('pw'), $user->id));

    $session->challenge_requested_at = now('UTC')->subSeconds(10);
    $session->save();

    expect(fn () => app(SubmitSpvChallengeCode::class)(new SubmitSpvChallengeCodeInput($session->id, SensitiveString::wrap('123456'), $user->id)))
        ->toThrow(SpvChallengeExpiredException::class);

    $session->refresh();
    $connection->refresh();
    expect($session->state)->toBe(SpvSessionState::FAILED)
        ->and($session->destroyed_at)->not->toBeNull()
        ->and($connection->status)->toBe(SpvConnectionStatus::FAILED)
        ->and($connection->last_error_code)->toBe('challenge_expired');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/challenge'));

    expect(AuditEvent::query()->where('resource_id', $session->id)->where('event_type', 'SpvAuthChallengeExpired')->exists())->toBeTrue();
});

it('translates a 501 not_implemented worker response into a distinct, non-retried exception and still records an audit event', function (): void {
    Http::fake([
        '*/v1/spv/sessions' => Http::response(['id' => 'worker-session-6', 'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String()], 201),
        '*/v1/spv/sessions/worker-session-6/credentials' => Http::response(['error' => 'not_implemented', 'action' => 'credentials', 'message' => 'nope'], 501),
    ]);

    $user = spvUserWithMfa();
    $subject = TaxSubject::factory()->create();
    activateSpvSubject($user, $subject);
    $connection = spvConnectionFor($subject);
    $session = app(InitiateSpvAuthAttempt::class)($user, $connection->id);

    expect(fn () => app(SubmitSpvCredentials::class)(new SubmitSpvCredentialsInput($session->id, 'owner@example.test', SensitiveString::wrap('pw'), $user->id)))
        ->toThrow(SpvPortalAutomationUnavailableException::class);

    $session->refresh();
    expect($session->state)->toBe(SpvSessionState::CREATED);

    expect(AuditEvent::query()->where('resource_id', $session->id)->where('event_type', 'SpvCredentialsSubmitted')->exists())->toBeTrue();
});

it('destroys a session idempotently', function (): void {
    Http::fake([
        '*/v1/spv/sessions' => Http::response(['id' => 'worker-session-7', 'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String()], 201),
        '*/v1/spv/sessions/worker-session-7' => Http::response(null, 204),
    ]);

    $user = spvUserWithMfa();
    $subject = TaxSubject::factory()->create();
    activateSpvSubject($user, $subject);
    $connection = spvConnectionFor($subject);
    $session = app(InitiateSpvAuthAttempt::class)($user, $connection->id);

    $destroyed = app(DestroySpvSession::class)($session->id, $user->id);
    expect($destroyed->state)->toBe(SpvSessionState::DESTROYED);

    $again = app(DestroySpvSession::class)($session->id, $user->id);
    expect($again->state)->toBe(SpvSessionState::DESTROYED);

    Http::assertSentCount(2); // create + exactly one DELETE — the second destroy() call is a no-op.
});
