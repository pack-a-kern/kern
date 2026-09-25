<?php

declare(strict_types=1);

namespace Kern\Documents\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Events\Concerns\BroadcastsOnDocumentsChannel;

/**
 * Native text extraction (or, for an image, the trivial "this needs OCR") finished for a
 * document. `$needsOcr` is the routing decision only — never the extracted text itself, never a
 * quality score breakdown that could leak page content shape. The client re-fetches the document
 * (and, once a review UI exists, its derivatives) over authorised HTTPS to see anything more
 * (`BLUEPRINT.md` §16).
 */
final class DocumentTextExtracted implements ShouldBroadcast
{
    use BroadcastsOnDocumentsChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $documentId,
        public readonly string $taxSubjectId,
        public readonly DocumentStatus $status,
        public readonly bool $needsOcr,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'DocumentTextExtracted';
    }

    /** @return array{document_id: string, status: string, needs_ocr: bool, progress: int} */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->documentId,
            'status' => $this->status->value,
            'needs_ocr' => $this->needsOcr,
            'progress' => 70,
        ];
    }
}
