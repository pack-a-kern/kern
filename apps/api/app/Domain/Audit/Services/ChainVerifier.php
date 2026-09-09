<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\ChainVerificationFailureReason;
use App\Domain\Audit\DataTransferObjects\ChainVerificationResult;
use App\Domain\Audit\Support\AuditEventHasher;
use DateTimeImmutable;
use DateTimeZone;

use function hash_equals;

use Illuminate\Database\ConnectionResolverInterface;

/**
 * Walks one subject's (or the system's) audit chain in sequence order and reports the exact
 * sequence number where it stops being trustworthy, per the task brief: "reports the exact
 * sequence number where it breaks", not merely "the chain is broken".
 *
 * A chain is trusted up to and including sequence N when, for every event 1..N:
 *
 * 1. `sequence` is exactly 1, 2, 3, ... with no gap and no repeat;
 * 2. its stored `previous_hash` equals the previous event's stored `event_hash` (or
 *    {@see AuditChainService::GENESIS_HASH} for sequence 1);
 * 3. recomputing `AuditEventHasher::hash()` from the row's own stored columns reproduces its
 *    stored `event_hash` exactly — i.e. no column of that row has changed since it was written.
 *
 * Finally, the {@see \App\Domain\Audit\Models\AuditChainHead} row for the chain must agree with
 * the last verified event (same `last_sequence`, same `last_event_hash`) — this catches a corrupted
 * or manually edited head pointer even when every event row itself is intact.
 *
 * This check is the second line of defence described in `docs/handoff/006-audit-chain.md`: the
 * `audit_events_deny_mutation` trigger stops an ordinary compromised application credential from
 * tampering with a row (BLUEPRINT.md §4.1's "SQL dump furat" / "application RCE" threats); this
 * verifier is what would actually notice a row edited by someone with enough Postgres privilege to
 * disable that trigger first (§4.1's "administrator DB curios" threat) — the scenario the periodic,
 * externally-signed Merkle checkpoint (§4.8, not yet built) exists to make detectable even without
 * ever re-running this verifier.
 */
final readonly class ChainVerifier
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {
    }

    public function verify(string $chainKey): ChainVerificationResult
    {
        $connection = $this->connections->connection();

        /**
         * @var \Illuminate\Support\Collection<int, object{
         *     id: string, chain_key: string, sequence: int, subject_id: ?string,
         *     actor_id: ?string, event_type: string, resource_type: string, resource_id: ?string,
         *     occurred_at: string, payload_hash: string, previous_hash: string,
         *     event_hash: string, signature_key_version: ?int,
         * }> $events
         */
        $events = $connection->table('audit_events')
            ->where('chain_key', $chainKey)
            ->orderBy('sequence')
            ->get();

        /** @var object{last_sequence: int, last_event_hash: string}|null $head */
        $head = $connection->table('audit_chain_heads')
            ->where('chain_key', $chainKey)
            ->first();

        if ($events->isEmpty()) {
            if ($head !== null && (int) $head->last_sequence !== 0) {
                return ChainVerificationResult::brokenAt(
                    $chainKey,
                    0,
                    0,
                    ChainVerificationFailureReason::CHAIN_NOT_FOUND,
                );
            }

            return ChainVerificationResult::ok($chainKey, 0);
        }

        $expectedPreviousHash = AuditChainService::GENESIS_HASH;
        $expectedSequence = 1;
        $verifiedCount = 0;
        $lastEventHash = $expectedPreviousHash;

        foreach ($events as $event) {
            $sequence = (int) $event->sequence;

            if ($sequence !== $expectedSequence) {
                $reason = $expectedSequence === 1
                    ? ChainVerificationFailureReason::SEQUENCE_DOES_NOT_START_AT_ONE
                    : ($sequence < $expectedSequence
                        ? ChainVerificationFailureReason::DUPLICATE_SEQUENCE
                        : ChainVerificationFailureReason::SEQUENCE_GAP);

                return ChainVerificationResult::brokenAt($chainKey, $verifiedCount, $sequence, $reason);
            }

            $storedPreviousHash = (string) $event->previous_hash;

            if (! hash_equals($expectedPreviousHash, $storedPreviousHash)) {
                return ChainVerificationResult::brokenAt(
                    $chainKey,
                    $verifiedCount,
                    $sequence,
                    ChainVerificationFailureReason::PREVIOUS_HASH_MISMATCH,
                );
            }

            $payload = AuditEventHasher::payload(
                id: (string) $event->id,
                chainKey: (string) $event->chain_key,
                sequence: $sequence,
                subjectId: $event->subject_id === null ? null : (string) $event->subject_id,
                actorId: $event->actor_id === null ? null : (string) $event->actor_id,
                eventType: (string) $event->event_type,
                resourceType: (string) $event->resource_type,
                resourceId: $event->resource_id === null ? null : (string) $event->resource_id,
                // Postgres returns `timestamptz` text formatted in the *session's* timezone, not
                // necessarily UTC — re-normalise to UTC explicitly before re-formatting so the
                // recomputed hash does not depend on the reading connection's `timezone` setting
                // (which the writing connection's Z-suffixed insert string was immune to: Postgres
                // parses a trailing `Z` as UTC on input regardless of session timezone).
                occurredAt: (new DateTimeImmutable((string) $event->occurred_at))
                    ->setTimezone(new DateTimeZone('UTC')),
                payloadHash: (string) $event->payload_hash,
                previousHash: $storedPreviousHash,
            );

            $recomputed = AuditEventHasher::hash($payload, $storedPreviousHash);
            $storedEventHash = (string) $event->event_hash;

            if (! hash_equals($recomputed, $storedEventHash)) {
                return ChainVerificationResult::brokenAt(
                    $chainKey,
                    $verifiedCount,
                    $sequence,
                    ChainVerificationFailureReason::EVENT_HASH_MISMATCH,
                );
            }

            $verifiedCount++;
            $expectedPreviousHash = $storedEventHash;
            $lastEventHash = $storedEventHash;
            $expectedSequence++;
        }

        $lastVerifiedSequence = $expectedSequence - 1;

        if (
            $head === null
            || (int) $head->last_sequence !== $lastVerifiedSequence
            || ! hash_equals($lastEventHash, (string) $head->last_event_hash)
        ) {
            return ChainVerificationResult::brokenAt(
                $chainKey,
                $verifiedCount,
                $lastVerifiedSequence,
                ChainVerificationFailureReason::HEAD_DOES_NOT_MATCH_LAST_EVENT,
            );
        }

        return ChainVerificationResult::ok($chainKey, $verifiedCount);
    }
}
