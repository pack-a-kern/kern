<?php

declare(strict_types=1);

namespace Kern\Audit\Support;

use DateTimeInterface;

/**
 * The single place `event_hash = SHA256(canonical_json(event_without_hash) || previous_hash)`
 * (`BLUEPRINT.md` §4.8) is implemented. Both the writer
 * ({@see \Kern\Audit\Services\AuditChainService}) and the verifier
 * ({@see \Kern\Audit\Services\ChainVerifier}) call this — if the two ever computed the hash
 * slightly differently, verification would fail on every event that was ever correctly written,
 * which is exactly the kind of duplicated "stable domain knowledge" `docs/CODE-QUALITY.md` §1
 * says must not exist in two places.
 *
 * `event_without_hash` is exactly the fields below, keyed as shown — this shape, plus
 * {@see CanonicalJson}'s rules, is the entire byte-stability contract. Changing either changes
 * every future `event_hash`; existing stored rows are never rewritten (`audit_events` is
 * append-only), so this must never change once anything has been written against it.
 *
 * `occurred_at` is formatted with {@see self::TIMESTAMP_FORMAT} (UTC, microsecond precision)
 * rather than hashed as a raw `DateTimeInterface`: the object's own string cast is not a stable,
 * documented format, and Postgres round-trips a `timestamptz` column back as a driver-formatted
 * string that must be re-parsed and re-formatted identically for verification to reproduce the
 * exact bytes that were hashed at write time.
 */
final class AuditEventHasher
{
    public const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @return array{
     *     id: string, chain_key: string, sequence: int, subject_id: ?string, actor_id: ?string,
     *     event_type: string, resource_type: string, resource_id: ?string, occurred_at: string,
     *     payload_hash: string, previous_hash: string,
     * }
     */
    public static function payload(
        string $id,
        string $chainKey,
        int $sequence,
        ?string $subjectId,
        ?string $actorId,
        string $eventType,
        string $resourceType,
        ?string $resourceId,
        DateTimeInterface $occurredAt,
        string $payloadHash,
        string $previousHash,
    ): array {
        return [
            'id' => $id,
            'chain_key' => $chainKey,
            'sequence' => $sequence,
            'subject_id' => $subjectId,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'occurred_at' => $occurredAt->format(self::TIMESTAMP_FORMAT),
            'payload_hash' => $payloadHash,
            'previous_hash' => $previousHash,
        ];
    }

    /**
     * @param array<string, mixed> $eventWithoutHash exactly the shape {@see self::payload()} returns
     */
    public static function hash(array $eventWithoutHash, string $previousHash): string
    {
        return hash('sha256', CanonicalJson::encode($eventWithoutHash) . $previousHash);
    }
}
