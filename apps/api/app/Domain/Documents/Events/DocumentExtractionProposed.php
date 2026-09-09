<?php

declare(strict_types=1);

namespace App\Domain\Documents\Events;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Events\Concerns\BroadcastsOnDocumentsChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A `document_extractions` row reached `PROPOSED` — an AI/extraction interpretation exists and is
 * waiting for human review (`BLUEPRINT.md` §14.6). Carries only ids and the closed
 * {@see ExtractionStatus}, never the extraction's `result_ciphertext` content.
 */
final class DocumentExtractionProposed implements ShouldBroadcast
{
    use BroadcastsOnDocumentsChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $documentId,
        public readonly string $taxSubjectId,
        public readonly string $extractionId,
        public readonly DocumentStatus $documentStatus,
        public readonly ExtractionStatus $extractionStatus,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'DocumentExtractionProposed';
    }

    /**
     * @return array{
     *     document_id: string,
     *     extraction_id: string,
     *     status: string,
     *     extraction_status: string,
     *     progress: int
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->documentId,
            'extraction_id' => $this->extractionId,
            'status' => $this->documentStatus->value,
            'extraction_status' => $this->extractionStatus->value,
            'progress' => 90,
        ];
    }
}
