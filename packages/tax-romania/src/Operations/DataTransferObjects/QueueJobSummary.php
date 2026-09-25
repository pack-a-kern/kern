<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\DataTransferObjects;

/**
 * One queued job as the operations screen may show it.
 *
 * Horizon stores each
 * job's serialized payload, and for this application that payload carries document ids, tax subject
 * ids and whatever `SerializesModels` would re-hydrate. The class name says what broke; the payload
 * would make this screen a bypass around every access control the rest of the application enforces
 * (`BLUEPRINT.md` §4.10). The exception is trimmed to its first line — the frames name container
 * paths and the message alone identifies the fault.
 */
final readonly class QueueJobSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $queue,
        public string $connection,
        /** `pending`, `reserved`, `completed` or `failed`, as Horizon records it. */
        public string $status,
        /** ISO-8601, or null for a job Horizon has no timestamp for yet. */
        public ?string $queuedAt,
        public ?string $completedAt,
        public ?string $failedAt,
        public ?string $exception,
        /** Set once a failure has been re-queued, so a row is not retried twice by mistake. */
        public bool $retried,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'status' => $this->status,
            'queued_at' => $this->queuedAt,
            'completed_at' => $this->completedAt,
            'failed_at' => $this->failedAt,
            'exception' => $this->exception,
            'retried' => $this->retried,
        ];
    }
}
