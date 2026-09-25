<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Spv\NoSensitiveLoggingTest;

use App\Domain\Identity\Enums\MfaMethodType;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserMfaMethod;
use App\Domain\Spv\Actions\InitiateSpvAuthAttempt;
use App\Domain\Spv\Actions\SubmitSpvChallengeCode;
use App\Domain\Spv\Actions\SubmitSpvCredentials;
use App\Domain\Spv\DataTransferObjects\SubmitSpvChallengeCodeInput;
use App\Domain\Spv\DataTransferObjects\SubmitSpvCredentialsInput;
use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Enums\SpvConnectionStatus;
use App\Domain\Spv\Enums\SpvCredentialPolicy;
use App\Domain\Spv\Models\SpvConnection;
use App\Domain\Spv\ValueObjects\SensitiveString;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

const SECRET_PASSWORD = 'sup3r-secret-marker-password';
const SECRET_CODE = '918273';

/**
 * `docs/AGENTS.md` §1 rule 12 / `BLUEPRINT.md` §13.2 steps 3/9 — captures the **real** log sink
 * (a dedicated `single` channel writing to its own file) across the full credential + challenge
 * flow, including a worker failure, and asserts the plaintext password/code never appear in it.
 * `Kern\Audit\Services\AuditChainService::hashPayload()` already guarantees the audit trail
 * itself carries only structural outcomes (`tests/Feature/Domain/Spv/CredentialFlowTest.php`
 * checks that directly); this test is the equivalent guarantee for the log stream.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
    config(['spv.browser_worker.auth_token' => 'test-worker-secret']);
});

it('never writes the submitted password or challenge code to the log, across success and failure paths', function (): void {
    $logPath = storage_path('logs/spv-no-sensitive-logging-test.log');
    @unlink($logPath);
    config(['logging.channels.spv_capture' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']]);
    config(['logging.default' => 'spv_capture']);

    Http::fake([
        '*/v1/spv/sessions' => Http::response(['id' => 'log-test-session', 'expiresAt' => now('UTC')->addMinutes(15)->toIso8601String()], 201),
        '*/v1/spv/sessions/log-test-session/credentials' => Http::response(['status' => 'awaiting_challenge']),
        '*/v1/spv/sessions/log-test-session/challenge' => Http::response(['status' => 'failed', 'reason' => 'invalid_code']),
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
