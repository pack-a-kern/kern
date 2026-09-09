<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Spv\SpvMfaGateTest;

use App\Domain\Identity\Enums\MfaMethodType;
use App\Domain\Identity\Exceptions\MfaNotEnrolledException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserMfaMethod;
use App\Domain\Spv\Actions\InitiateSpvAuthAttempt;
use App\Domain\Spv\Actions\RegisterSpvConnection;
use App\Domain\Spv\Contracts\SpvBrowserSessionClient;
use App\Domain\Spv\DataTransferObjects\PortalAuthResult;
use App\Domain\Spv\DataTransferObjects\RegisterSpvConnectionInput;
use App\Domain\Spv\DataTransferObjects\SpvBrowserSession;
use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Enums\SpvConnectionStatus;
use App\Domain\Spv\Enums\SpvCredentialPolicy;
use App\Domain\Spv\ValueObjects\SensitiveString;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * `BLUEPRINT.md` §4.7 — "MFA obligatoriu pentru conturi care conectează SPV." Both call sites this
 * milestone builds on top of `App\Domain\Identity\Support\SpvMfaPolicy`
 * (`RegisterSpvConnection`, `InitiateSpvAuthAttempt`) must refuse to proceed without it.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('refuses to register an SPV connection for a user with no MFA enrolled', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    app(RegisterSpvConnection::class)($user, new RegisterSpvConnectionInput(
        taxSubjectId: $subject->id,
        authMethod: SpvAuthMethod::USER_PASSWORD_OTP,
        username: 'someone@example.test',
    ));
})->throws(MfaNotEnrolledException::class);

it('allows registering an SPV connection once the user has a confirmed TOTP method', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();
    enrollTotpFor($user);

    $connection = app(RegisterSpvConnection::class)($user, new RegisterSpvConnectionInput(
        taxSubjectId: $subject->id,
        authMethod: SpvAuthMethod::USER_PASSWORD_OTP,
        username: 'someone@example.test',
    ));

    expect($connection->status)->toBe(SpvConnectionStatus::PENDING)
        ->and($connection->credential_policy)->toBe(SpvCredentialPolicy::EPHEMERAL_ONLY);
});

it('refuses to initiate an auth attempt for a user whose MFA was un-enrolled since registration', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();
    $method = enrollTotpFor($user);

    $connection = app(RegisterSpvConnection::class)($user, new RegisterSpvConnectionInput(
        taxSubjectId: $subject->id,
        authMethod: SpvAuthMethod::USER_PASSWORD_OTP,
        username: 'someone@example.test',
    ));

    $method->delete();

    app()->instance(SpvBrowserSessionClient::class, new class () implements SpvBrowserSessionClient {
        public function createSession(): SpvBrowserSession
        {
            throw new RuntimeException('must not be called once MFA is missing');
        }

        public function submitCredentials(string $browserSessionRef, string $username, SensitiveString $password): PortalAuthResult
        {
            throw new RuntimeException('unused');
        }

        public function submitChallengeCode(string $browserSessionRef, SensitiveString $code): PortalAuthResult
        {
            throw new RuntimeException('unused');
        }

        public function destroySession(string $browserSessionRef): void
        {
            throw new RuntimeException('unused');
        }

        public function requestSync(string $browserSessionRef): void
        {
            throw new RuntimeException('unused');
        }
    });

    app(InitiateSpvAuthAttempt::class)($user, $connection->id);
})->throws(MfaNotEnrolledException::class);

function enrollTotpFor(User $user): UserMfaMethod
{
    return UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);
}
