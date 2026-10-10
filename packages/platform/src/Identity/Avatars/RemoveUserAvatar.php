<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Avatars;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;

/**
 * Takes somebody's picture back off their account, leaving the initials that were there before.
 *
 * The row is cleared before the file is deleted, and that order is deliberate: if the delete fails the
 * account already shows initials again, which is the outcome the person asked for, and what is left
 * behind is an unreferenced file rather than a column pointing at nothing.
 */
final readonly class RemoveUserAvatar
{
    public function __construct(
        private AvatarStore $store,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(User $user, ?string $actorId): User
    {
        $path = $user->avatar_path;

        if ($path === null) {
            return $user;
        }

        $user->avatar_path = null;
        $user->avatar_updated_at = null;
        $user->save();

        $this->store->delete($path);

        $userKey = $user->getKey();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: 'USER_AVATAR_REMOVED',
            resourceType: 'user',
            resourceId: is_scalar($userKey) ? (string) $userKey : '',
            payloadHash: $this->audit->hashPayload([]),
        ));

        return $user;
    }
}
