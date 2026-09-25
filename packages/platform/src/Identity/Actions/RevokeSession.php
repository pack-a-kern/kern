<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Exceptions\SessionNotFoundException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserSession;
use Kern\Platform\Identity\Support\SessionRegistrar;

/**
 * Revokes one of a user's own sessions (`BLUEPRINT.md` §4.7's "explicit revoke" device-management
 * requirement). Ownership is checked here, before delegating to `SessionRegistrar::revoke()` —
 * that class trusts the session id it is given belongs to whoever is asking, this action is what
 * makes that true.
 */
final readonly class RevokeSession
{
    public function __construct(
        private AuditChainService $audit,
        private SessionRegistrar $sessions,
    ) {
    }

    public function __invoke(User $user, string $sessionId): void
    {
        $session = UserSession::query()
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->first();

        if ($session === null) {
            throw SessionNotFoundException::create();
        }

        $this->sessions->revoke($sessionId);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'AuthSessionRevoked',
            resourceType: 'UserSession',
            resourceId: $sessionId,
            payloadHash: $this->audit->hashPayload(['reason' => 'user_requested']),
        ));
    }
}
