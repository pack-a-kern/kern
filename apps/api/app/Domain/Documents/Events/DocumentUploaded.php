<?php

declare(strict_types=1);

namespace App\Domain\Documents\Events;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Events\Concerns\BroadcastsOnDocumentsChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A document entered quarantine. `BLUEPRINT.md` §16 — status and progress only. `broadcastWith()` is
 * a fixed, closed set of scalars; there is no path by which a filename, extracted text, or any
 * other PII-bearing field could be added here without editing this array by hand, so this is a
 * property of the type, not a discipline every future editor has to remember (`Feature\Domain\
 * Documents\BroadcastPayloadContainsNoPiiTest` pins this).
 */
final class DocumentUploaded implements ShouldBroadcast
{
    use BroadcastsOnDocumentsChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $documentId,
        public readonly string $taxSubjectId,
        public readonly DocumentStatus $status,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'DocumentUploaded';
    }

    /** @return array{document_id: string, status: string, progress: int} */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->documentId,
            'status' => $this->status->value,
            'progress' => 10,
        ];
    }
}
