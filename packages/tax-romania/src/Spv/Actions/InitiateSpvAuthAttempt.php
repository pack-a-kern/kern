<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\SpvAuthMethodMismatchException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;

/**
 * `BLUEPRINT.md` §13.2 steps 1-4: the user initiates "Connect SPV", Laravel starts a browser worker
 * session, and creates the local `spv_sessions` row that tracks it.
 *
 * §4.7's MFA requirement is enforced by the `verify-recent` middleware on this action's route, not
 * here. It used to be checked in this constructor, as enrolment — which an attacker holding the
 * session passes without owning any factor. The route now demands a code, recently, which is the
 * thing the requirement was always after.
 */
final readonly class InitiateSpvAuthAttempt
{
    public function __construct(
        private SpvBrowserSessionClient $client,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(User $actingUser, string $connectionId): SpvSession
    {
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
