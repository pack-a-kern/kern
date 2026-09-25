<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\SpvMfaPolicy;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\SpvAuthMethodMismatchException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;

/**
 * `BLUEPRINT.md` §13.2 steps 1-4: the user initiates "Connect SPV", Laravel starts a browser worker
 * session, and creates the local `spv_sessions` row that tracks it. Re-checks MFA here as well as
 * at registration time (`RegisterSpvConnection`) — MFA could have been un-enrolled since a
 * connection was first registered, and §4.7 gates the act of connecting, not just the one-time
 * setup.
 */
final readonly class InitiateSpvAuthAttempt
{
    public function __construct(
        private SpvMfaPolicy $mfaPolicy,
        private SpvBrowserSessionClient $client,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(User $actingUser, string $connectionId): SpvSession
    {
        $this->mfaPolicy->ensureStrongAuthentication($actingUser);

        /** @var SpvConnection $connection */
        $connection = SpvConnection::query()->findOrFail($connectionId);

        if ($connection->auth_method !== SpvAuthMethod::USER_PASSWORD_OTP) {
            throw SpvAuthMethodMismatchException::expected(SpvAuthMethod::USER_PASSWORD_OTP, $connection->auth_method);
        }

        $browserSession = $this->client->createSession();

        $session = SpvSession::create([
            'tax_subject_id' => $connection->tax_subject_id,
            'connection_id' => $connection->id,
            'browser_session_ref' => $browserSession->id,
            'state' => SpvSessionState::CREATED,
            'created_at' => now('UTC'),
            'expires_at' => $browserSession->expiresAt,
        ]);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $session->tax_subject_id,
            actorId: $actingUser->id,
            eventType: 'SpvAuthAttemptInitiated',
            resourceType: 'SpvSession',
            resourceId: $session->id,
            payloadHash: $this->audit->hashPayload([]),
        ));

        return $session;
    }
}
