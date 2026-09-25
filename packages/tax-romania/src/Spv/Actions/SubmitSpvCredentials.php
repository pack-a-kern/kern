<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\DataTransferObjects\SubmitSpvCredentialsInput;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\InvalidSpvSessionTransitionException;
use Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;
use Kern\TaxRomania\Spv\Services\ApplyPortalAuthResult;

/**
 * `BLUEPRINT.md` §13.2 step 3: credentials cross into `apps/spv-browser` here, over a single
 * synchronous internal HTTP call — never queued (`docs/AGENTS.md` rule 5 forbids a credential in
 * a queue payload), never assigned to any variable that outlives this method beyond
 * `$input->password` itself, which the caller (an HTTP controller, out of this module's scope)
 * must discard immediately after this call returns.
 *
 * Today, {@see SpvBrowserSessionClient::submitCredentials()} always throws
 * {@see SpvPortalAutomationUnavailableException} (no portal adapter exists yet) — this action
 * still records that attempt on the audit chain and leaves the session at `CREATED`, since a
 * missing adapter is an operational limitation, not a rejected login.
 */
final readonly class SubmitSpvCredentials
{
    public function __construct(
        private SpvBrowserSessionClient $client,
        private ApplyPortalAuthResult $applyResult,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(SubmitSpvCredentialsInput $input): SpvSession
    {
        /** @var SpvSession $session */
        $session = SpvSession::query()->findOrFail($input->sessionId);

        if ($session->state !== SpvSessionState::CREATED) {
            throw InvalidSpvSessionTransitionException::forState($session->id, $session->state, SpvSessionState::CREATED);
        }

        try {
            $result = $this->client->submitCredentials($session->browser_session_ref, $input->username, $input->password);
        } catch (SpvPortalAutomationUnavailableException|SpvWorkerUnavailableException $exception) {
            $this->recordAttempt($session, $input->actorId, 'unavailable');

            throw $exception;
        }

        /** @var SpvConnection $connection */
        $connection = SpvConnection::query()->findOrFail($session->connection_id);

        ($this->applyResult)($session, $connection, $result);

        $this->recordAttempt($session, $input->actorId, $result->state->value);

        return $session;
    }

    private function recordAttempt(SpvSession $session, ?string $actorId, string $outcome): void
    {
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $session->tax_subject_id,
            actorId: $actorId,
            eventType: 'SpvCredentialsSubmitted',
            resourceType: 'SpvSession',
            resourceId: $session->id,
            payloadHash: $this->audit->hashPayload(['outcome' => $outcome]),
        ));
    }
}
