<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Models\Tag;
use App\Domain\Wallet\Services\TagDirectory;
use App\Domain\Wallet\Services\WalletAudit;

/**
 * Rename a tag.
 *
 * Refused when the taxpayer already has a tag by that name: folding two tags into one is a merge,
 * which deletes a row and changes what every transaction carrying it says, and a rename that
 * silently did that would be a destructive act behind a harmless-looking control.
 */
final readonly class RenameTag
{
    public function __construct(
        private TagDirectory $tags,
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(Tag $tag, string $name, ?string $actorId = null): bool
    {
        if (! $this->tags->rename($tag, $name)) {
            return false;
        }

        $this->audit->record(
            subjectId: $tag->tax_subject_id,
            actorId: $actorId,
            eventType: WalletAuditEvent::TAG_RENAMED->value,
            resourceType: WalletAuditEvent::TAG_RENAMED->resourceType(),
            resourceId: $tag->id,
            // The name is not in the payload: it is encrypted on the row precisely because a tag can
            // say something about a person, and an audit chain that hashed it in clear would undo that.
            payload: [],
        );

        return true;
    }
}
