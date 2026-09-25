<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Models\Tag;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Delete a tag.
 *
 * The links go with it — both pivots cascade — and nothing else does: a tag is a label, so removing
 * it loses a way of filtering and not a figure. No transaction, split or category is touched, which
 * is why this needs no confirmation beyond the one the screen asks.
 */
final readonly class DeleteTag
{
    public function __construct(private WalletAudit $audit)
    {
    }

    public function __invoke(Tag $tag, ?string $actorId = null): void
    {
        $subjectId = $tag->tax_subject_id;
        $id = $tag->id;

        $tag->delete();

        $this->audit->record(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: WalletAuditEvent::TAG_DELETED->value,
            resourceType: WalletAuditEvent::TAG_DELETED->resourceType(),
            resourceId: $id,
            payload: [],
        );
    }
}
