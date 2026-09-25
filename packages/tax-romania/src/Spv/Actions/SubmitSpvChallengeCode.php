<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Actions;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\DataTransferObjects\SubmitSpvChallengeCodeInput;
use Kern\TaxRomania\Spv\Enums\SpvConnectionStatus;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\InvalidSpvSessionTransitionException;
use Kern\TaxRomania\Spv\Exceptions\SpvChallengeExpiredException;
use Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;
use Kern\TaxRomania\Spv\Services\ApplyPortalAuthResult;

/**
 * `BLUEPRINT.md` §13.2 steps 6-9: the safety code the user was challenged for. Requires the session
 * to already be `AWAITING_USER_CHALLENGE` (`Kern\TaxRomania\Spv\Actions\SubmitSpvCredentials` is the
 * only path there). Enforces the code's TTL (`config('spv.challenge_ttl_seconds')`) itself,
 * *before* ever calling `apps/spv-browser` — defence in depth on top of whatever TTL a future
 * real portal adapter enforces on its own side: an expired code is never forwarded.
 */
final readonly class SubmitSpvChallengeCode
{
    public function __construct(
        private SpvBrowserSessionClient $client,
        private ApplyPortalAuthResult $applyResult,
        private AuditChainService $audit,
        private ConfigRepository $config,
    ) {
    }

    public function __invoke(SubmitSpvChallengeCodeInput $input): SpvSession
    {
        /** @var SpvSession $session */
        $session = SpvSession::query()->findOrFail($input->sessionId);

        if ($session->state !== SpvSessionState::AWAITING_USER_CHALLENGE || $session->challenge_requested_at === null) {
            throw InvalidSpvSessionTransitionException::forState($session->id, $session->state, SpvSessionState::AWAITING_USER_CHALLENGE);
        }

        /** @var SpvConnection $connection */
        $connection = SpvConnection::query()->findOrFail($session->connection_id);

        $ttlSeconds = $this->config->integer('spv.challenge_ttl_seconds');

        if ($session->challenge_requested_at->addSeconds($ttlSeconds)->isPast()) {
            $this->expire($session, $connection, $input->actorId);

            throw SpvChallengeExpiredException::forSession($session->id);
        }

        try {
            $result = $this->client->submitChallengeCode($session->browser_session_ref, $input->code);
        } catch (SpvPortalAutomationUnavailableException|SpvWorkerUnavailableException $exception) {
            $this->recordAttempt($session, $input->actorId, 'unavailable');

            throw $exception;
        }

        ($this->applyResult)($session, $connection, $result);

        $this->recordAttempt($session, $input->actorId, $result->state->value);

        return $session;
    }

    private function expire(SpvSession $session, SpvConnection $connection, ?string $actorId): void
    {
        $session->state = SpvSessionState::FAILED;
        $session->destroyed_at = now('UTC');
        $session->save();

        $connection->status = SpvConnectionStatus::FAILED;
        $connection->last_error_code = 'challenge_expired';
        $connection->save();

        try {
            $this->client->destroySession($session->browser_session_ref);
        } catch (SpvWorkerUnavailableException) {
            // Best-effort teardown: the local session is already marked FAILED/destroyed
            // regardless of whether the worker could be reached to release its own resources.
        }

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $session->tax_subject_id,
            actorId: $actorId,
            eventType: 'SpvAuthChallengeExpired',
            resourceType: 'SpvSession',
            resourceId: $session->id,
            payloadHash: $this->audit->hashPayload([]),
        ));
    }

    private function recordAttempt(SpvSession $session, ?string $actorId, string $outcome): void
    {
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $session->tax_subject_id,
            actorId: $actorId,
            eventType: 'SpvChallengeCodeSubmitted',
            resourceType: 'SpvSession',
            resourceId: $session->id,
            payloadHash: $this->audit->hashPayload(['outcome' => $outcome]),
        ));
    }
}
