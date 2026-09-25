<?php

declare(strict_types=1);

namespace App\Domain\Spv\Actions;

use App\Domain\Spv\DataTransferObjects\RegisterSpvConnectionInput;
use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Enums\SpvConnectionStatus;
use App\Domain\Spv\Enums\SpvCredentialPolicy;
use App\Domain\Spv\Models\SpvConnection;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\SpvMfaPolicy;

/**
 * `BLUEPRINT.md` §13.2 step 1 ("Connect SPV") and §4.7 ("MFA obligatoriu pentru conturi care
 * conectează SPV") — the single call site that enforces the MFA gate before a `spv_connections`
 * row can exist at all. `$actingUser` must be the user performing the connection; this action
 * never resolves "who is calling" itself (no session/request access in the domain layer, matching
 * `Kern\Platform\Identity\Actions\*`'s own framework-free convention).
 *
 * @throws \Kern\Platform\Identity\Exceptions\MfaNotEnrolledException
 */
final readonly class RegisterSpvConnection
{
    public function __construct(
        private SpvMfaPolicy $mfaPolicy,
        private BlindIndexHasher $blindIndex,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(User $actingUser, RegisterSpvConnectionInput $input): SpvConnection
    {
        $this->mfaPolicy->ensureStrongAuthentication($actingUser);

        $usernameBlindIndex = null;

        if ($input->authMethod === SpvAuthMethod::USER_PASSWORD_OTP && $input->username !== null) {
            $usernameBlindIndex = $this->blindIndex->compute(Normalizer::text($input->username));
        }

        $connection = SpvConnection::create([
            'tax_subject_id' => $input->taxSubjectId,
            'auth_method' => $input->authMethod,
            'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
            'username_ciphertext' => $input->username,
            'username_blind_index' => $usernameBlindIndex,
            'status' => SpvConnectionStatus::PENDING,
        ]);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $connection->tax_subject_id,
            actorId: $actingUser->id,
            eventType: 'SpvConnectionRegistered',
            resourceType: 'SpvConnection',
            resourceId: $connection->id,
            payloadHash: $this->audit->hashPayload(['auth_method' => $input->authMethod->value]),
        ));

        return $connection;
    }
}
