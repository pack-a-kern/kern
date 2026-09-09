<?php

declare(strict_types=1);

namespace App\Domain\Spv\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Spv\Contracts\SpvBrowserSessionClient;
use App\Domain\Spv\Enums\SpvSessionState;
use App\Domain\Spv\Models\SpvSession;

/**
 * `BLUEPRINT.md` §13.2 step 11: "sesiunea se distruge conform policy." Tears down the worker's
 * ephemeral browser context and marks the local row `DESTROYED` — idempotent, so calling this on
 * an already-destroyed session is a no-op rather than an error (both the worker's own
 * `DELETE .../sessions/{id}` and this method treat "already gone" as success).
 */
final readonly class DestroySpvSession
{
    public function __construct(
        private SpvBrowserSessionClient $client,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(string $sessionId, ?string $actorId): SpvSession
    {
        /** @var SpvSession $session */
        $session = SpvSession::query()->findOrFail($sessionId);

        if ($session->state === SpvSessionState::DESTROYED) {
            return $session;
        }

        $this->client->destroySession($session->browser_session_ref);

        $session->state = SpvSessionState::DESTROYED;
        $session->destroyed_at = now('UTC');
        $session->save();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $session->tax_subject_id,
            actorId: $actorId,
            eventType: 'SpvSessionDestroyed',
            resourceType: 'SpvSession',
            resourceId: $session->id,
            payloadHash: $this->audit->hashPayload([]),
        ));

        return $session;
    }
}
