<?php

declare(strict_types=1);

namespace Kern\Documents\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kern\Documents\Enums\AiExtractionOutcome;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Events\Concerns\BroadcastsOnDocumentsChannel;

/**
 * The external-AI attempt for a document ended without a proposal (`BLUEPRINT.md` §16).
 *
 * The counterpart to {@see DocumentExtractionProposed}, which covers the successful case and is
 * dispatched instead of this one. Between them, every document leaving the pipeline emits exactly
 * one terminal event — so a review screen watching a document never sits waiting for something that
 * is never coming, which is precisely what it did before this event existed.
 *
 * Carries the closed {@see AiExtractionOutcome} and nothing else. The outcome is safe to broadcast
 * for the same reason it is safe to store: it is an enum case, never a provider's own error text,
 * which can echo back fragments of the document it was sent (`BLUEPRINT.md` §4.10).
 */
final class DocumentAiExtractionFinished implements ShouldBroadcast
{
    use BroadcastsOnDocumentsChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $documentId,
        public readonly string $taxSubjectId,
        public readonly DocumentStatus $documentStatus,
        public readonly AiExtractionOutcome $outcome,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'DocumentAiExtractionFinished';
    }

    /**
     * @return array{document_id: string, status: string, outcome: string, is_failure: bool, progress: int}
     */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->documentId,
            // Carried even though this event is about the reading, not the status: the client's
            // shared lifecycle-event parser requires it, and an event missing it is silently dropped.
            'status' => $this->documentStatus->value,
            'outcome' => $this->outcome->value,
            'is_failure' => $this->outcome->isFailure(),
            // The document itself is READY and usable by hand either way; the pipeline is done with
            // it. Reporting anything less than complete would leave a progress bar stuck forever.
            'progress' => 100,
        ];
    }
}
