<?php

declare(strict_types=1);

namespace Kern\Audit\DataTransferObjects;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * What another module hands to {@see \Kern\Audit\Services\AuditChainService::record()} to
 * append one tamper-evident event (BLUEPRINT.md §4.8).
 *
 * Audit never stores the business payload itself (that lives in the caller's own tables/objects
 * encrypted under its own DEK, per §4.6) — only a hash of it, so the caller must compute
 * `payloadHash` itself. {@see \Kern\Audit\Services\AuditChainService::hashPayload()} is a
 * convenience for callers who want the same canonical hashing rule the chain itself uses; it is
 * not mandatory, since some callers may need to hash an already-serialized artifact instead of a
 * PHP array (e.g. a stored file's SHA-256, BLUEPRINT.md §4.9).
 *
 * `occurredAt` is required, never defaulted to `now()` inside this DTO: per `docs/AGENTS.md` §6,
 * nothing in the audit trail may have an implicit clock. A caller that genuinely means "now" uses
 * {@see self::occurringNow()}, which makes that choice visible at the call site.
 */
final readonly class AuditEventInput
{
    public function __construct(
        public ?string $subjectId,
        public ?string $actorId,
        public string $eventType,
        public string $resourceType,
        public ?string $resourceId,
        public string $payloadHash,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function occurringNow(
        ?string $subjectId,
        ?string $actorId,
        string $eventType,
        string $resourceType,
        ?string $resourceId,
        string $payloadHash,
    ): self {
        return new self(
            $subjectId,
            $actorId,
            $eventType,
            $resourceType,
            $resourceId,
            $payloadHash,
            CarbonImmutable::now('UTC'),
        );
    }
}
