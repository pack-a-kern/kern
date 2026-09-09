<?php

declare(strict_types=1);

namespace App\Domain\Documents\Events;

use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Documents\Events\Concerns\BroadcastsOnDocumentsChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The text-extraction service rejected this specific document for one of its closed, documented
 * reasons (`docs/handoff/024-extraction-endpoint.md`) — an encrypted PDF, too many pages, and so
 * on. `$rejectionReason` is the closed {@see RejectionReason} enum, never the service's free-text
 * message (`BLUEPRINT.md` §16 — status/progress only). Mirrors `DocumentScanned`'s own contract for
 * the equivalent ClamAV-stage rejection.
 */
final class DocumentTextExtractionRejected implements ShouldBroadcast
{
    use BroadcastsOnDocumentsChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $documentId,
        public readonly string $taxSubjectId,
        public readonly RejectionReason $rejectionReason,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'DocumentTextExtractionRejected';
    }

    /** @return array{document_id: string, status: string, rejection_reason: string, progress: int} */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->documentId,
            'status' => 'REJECTED',
            'rejection_reason' => $this->rejectionReason->value,
            'progress' => 100,
        ];
    }
}
