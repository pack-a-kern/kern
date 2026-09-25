<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Models\Tag;
use Kern\TaxRomania\Wallet\Services\TagDirectory;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Fold one tag into another.
 *
 * Tags grow by themselves — typed on a form, proposed by a model reading a statement — so a taxpayer
 * ends up with "restaurants" and "eating out" meaning one thing. Merging moves every transaction and
 * every remembered rule onto the surviving tag and deletes the other.
 *
 * It deletes a row, so it is asked for explicitly and recorded, with both ids in the payload: ids are
 * not personal data, and "which tag went where" is the one thing someone reading the log needs.
 */
final readonly class MergeTags
{
    public function __construct(
        private TagDirectory $tags,
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(Tag $from, Tag $into, ?string $actorId = null): void
    {
        if ($from->id === $into->id) {
            return;
        }

        $this->tags->merge($from, $into);

        $this->audit->record(
            subjectId: $into->tax_subject_id,
            actorId: $actorId,
            eventType: WalletAuditEvent::TAGS_MERGED->value,
            resourceType: WalletAuditEvent::TAGS_MERGED->resourceType(),
            resourceId: $into->id,
            payload: ['merged_tag_id' => $from->id],
        );
    }
}
