<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\NoSensitiveLoggingTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Spv\Actions\InitiateSpvAuthAttempt;
use Kern\TaxRomania\Spv\Actions\SubmitSpvChallengeCode;
use Kern\TaxRomania\Spv\Actions\SubmitSpvCredentials;
use Kern\TaxRomania\Spv\DataTransferObjects\SubmitSpvChallengeCodeInput;
use Kern\TaxRomania\Spv\DataTransferObjects\SubmitSpvCredentialsInput;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvConnectionStatus;
use Kern\TaxRomania\Spv\Enums\SpvCredentialPolicy;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

const SECRET_PASSWORD = 'sup3r-secret-marker-password';
const SECRET_CODE = '918273';

/**
 * `docs/AGENTS.md` §1 rule 12 / `BLUEPRINT.md` §13.2 steps 3/9 — captures the **real** log sink
 * (a dedicated `single` channel writing to its own file) across the full credential + challenge
 * flow, including a worker failure, and asserts the plaintext password/code never appear in it.
 * `Kern\Audit\Services\AuditChainService::hashPayload()` already guarantees the audit trail
 * itself carries only structural outcomes (`tests/Feature/TaxRomania/Spv/CredentialFlowTest.php`
 * checks that directly); this test is the equivalent guarantee for the log stream.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    config(['browser_worker.auth_token' => 'test-worker-secret']);
});

it('never writes the submitted password or challenge code to the log, across success and failure paths', function (): void {
    $logPath = storage_path('logs/spv-no-sensitive-logging-test.log');
    @unlink($logPath);
    config(['logging.channels.spv_capture' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']]);
    config(['logging.default' => 'spv_capture']);

    // Both steps post to the one run route now — the sequence is in the body — so the two answers
    // come back in order rather than from two stubs.
    Http::fake([
        '*/v1/sessions' => Http::response(
            ['session_id' => 'log-test-session', 'expires_at' => now('UTC')->addMinutes(15)->toIso8601String()],
            201,
        ),
        '*/run' => Http::sequence()
            ->push(['outcome' => 'awaiting_challenge', 'data' => []])
            ->push(['outcome' => 'failed', 'message' => 'invalid_code', 'data' => []]),
    ]);

    $user = User::factory()->create();
    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);
    $subject = TaxSubject::factory()->create();
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

    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::USER_PASSWORD_OTP,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'username_ciphertext' => 'owner@example.test',
        'status' => SpvConnectionStatus::PENDING,
    ]);

    $session = app(InitiateSpvAuthAttempt::class)($user, $connection->id);

    app(SubmitSpvCredentials::class)(new SubmitSpvCredentialsInput(
        sessionId: $session->id,
        username: 'owner@example.test',
        password: SensitiveString::wrap(SECRET_PASSWORD),
        actorId: $user->id,
    ));

    app(SubmitSpvChallengeCode::class)(new SubmitSpvChallengeCodeInput(
        sessionId: $session->id,
        code: SensitiveString::wrap(SECRET_CODE),
        actorId: $user->id,
    ));

    $contents = is_file($logPath) ? (string) file_get_contents($logPath) : '';
    @unlink($logPath);

    expect($contents)->not->toContain(SECRET_PASSWORD)
        ->and($contents)->not->toContain(SECRET_CODE);
});
