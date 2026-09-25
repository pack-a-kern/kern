<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\SessionRegistrar;

/**
 * `BLUEPRINT.md` §4.7's "explicit revoke all sessions." `$exceptSessionId` lets the caller's own
 * current session survive the sweep ("log out everywhere else") — pass `null` to revoke
 * everything including the caller's own session.
 */
final readonly class RevokeAllSessions
{
    public function __construct(
        private AuditChainService $audit,
        private SessionRegistrar $sessions,
    ) {
    }

    public function __invoke(User $user, ?string $exceptSessionId): int
    {
        $revoked = $this->sessions->revokeAllForUser($user->id, $exceptSessionId);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'AuthAllSessionsRevoked',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['count' => $revoked]),
        ));

        return $revoked;
    }
}
