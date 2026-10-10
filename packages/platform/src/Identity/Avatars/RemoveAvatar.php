<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Avatars;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * Takes a picture back off whatever was wearing it, leaving the initials that were there before.
 *
 * The row is cleared before the file is deleted, and that order is deliberate: if the delete fails the
 * account already shows initials again, which is the outcome the person asked for, and what is left
 * behind is an unreferenced file rather than a column pointing at nothing.
 */
final readonly class RemoveAvatar
{
    public function __construct(
        private AvatarStore $store,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(HasAvatar $owner, ?string $actorId): HasAvatar
    {
        $path = $owner->avatarPath();

        if ($path === null) {
            return $owner;
        }

        $owner->saveAvatar(null, null);

        $this->store->delete($path);

        $ownerType = $owner->avatarOwnerType();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: strtoupper($ownerType) . '_AVATAR_REMOVED',
            resourceType: $ownerType,
            resourceId: $owner->avatarOwnerId(),
            payloadHash: $this->audit->hashPayload([]),
        ));

        return $owner;
    }
}
