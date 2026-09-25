<?php

declare(strict_types=1);

namespace Kern\Documents\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\RejectionReason;
use Kern\Documents\Events\Concerns\BroadcastsOnDocumentsChannel;

/**
 * The ClamAV scan finished with a verdict — `$status` is `SCANNED` (clean, promoted out of
 * quarantine) or `REJECTED` (infected). `$rejectionReason` is the closed
 * {@see RejectionReason} enum, never a virus signature name or any other free-text scanner output
 * (`BLUEPRINT.md` §16 — status/progress only, and `docs/AGENTS.md` rule 12 — never log/broadcast
 * anything that could carry attacker-influenced content).
 *
 * Never fired for "scanner unreachable" — that leaves the document in `QUARANTINE` with no
 * broadcast at all (`Kern\Documents\Scanning\VirusScanner`'s fail-closed contract); this
 * event only exists once a real verdict was reached.
 */
final class DocumentScanned implements ShouldBroadcast
{
    use BroadcastsOnDocumentsChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $documentId,
        public readonly string $taxSubjectId,
        public readonly DocumentStatus $status,
        public readonly ?RejectionReason $rejectionReason,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'DocumentScanned';
    }

    /** @return array{document_id: string, status: string, rejection_reason: string|null, progress: int} */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->documentId,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejectionReason?->value,
            'progress' => $this->status === DocumentStatus::REJECTED ? 100 : 40,
        ];
    }
}
