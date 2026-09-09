<?php

declare(strict_types=1);

namespace App\Domain\Audit\DataTransferObjects;

use DateTimeImmutable;

/**
 * The durable result of appending one event: exactly what was written to `audit_events`,
 * returned to the caller so it can, e.g., reference `id` from its own tables without re-querying.
 */
final readonly class AuditEventRecord
{
    public function __construct(
        public string $id,
        public string $chainKey,
        public int $sequence,
        public ?string $subjectId,
        public ?string $actorId,
        public string $eventType,
        public string $resourceType,
        public ?string $resourceId,
        public DateTimeImmutable $occurredAt,
        public string $payloadHash,
        public string $previousHash,
        public string $eventHash,
        public ?int $signatureKeyVersion,
    ) {
    }
}
