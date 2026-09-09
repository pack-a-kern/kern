<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\DataTransferObjects\AuditEventRecord;
use App\Domain\Audit\Support\AuditEventHasher;
use App\Domain\Audit\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Appends events to `audit_events`, one tamper-evident, hash-linked chain per subject
 * (`BLUEPRINT.md` §4.8).
 *
 * This is the module's public entry point for other bounded contexts — per `docs/AGENTS.md` §6, a
 * module never imports another module's Eloquent models, but "an application service" is one of
 * the explicitly sanctioned shapes for cross-module traffic, so callers depend on this class
 * directly rather than on `App\Domain\Audit\Models\AuditEvent`. There is deliberately no separate
 * `AuditEventRecorder` interface: with exactly one implementation and no test needing a fake
 * (Pest exercises this class itself against a real Postgres connection), an interface would be
 * the "pointless abstraction" `docs/CODE-QUALITY.md` §1 warns against. Add one, bound in a new
 * `AuditServiceProvider`, the day a second implementation or a substitution boundary is needed.
 *
 * ## Chain scoping
 *
 * `BLUEPRINT.md` §4.8 does not literally name a "chain key" column — the schema there implies one
 * chain per subject via `subject_id`. This service makes that scoping explicit as `chain_key` so
 * writes to different subjects' chains never contend for the same lock (the whole point of "per-
 * subject chains, not one global chain" — a global chain would serialize every audited action in
 * the system through one row). `chain_key` is `subject_id` verbatim when present, or the sentinel
 * {@see self::SYSTEM_CHAIN_KEY} for events with no subject (e.g. a login attempt before any
 * `TaxSubject` context exists). A subject id is always a UUID string, so it can never collide with
 * the literal string `"system"`.
 *
 * ## Canonical payload for `event_hash`
 *
 * The exact field shape hashed, and the formula itself, live in
 * {@see \App\Domain\Audit\Support\AuditEventHasher} — shared verbatim with
 * {@see \App\Domain\Audit\Services\ChainVerifier} so writing and verifying can never drift apart.
 *
 * `signature_key_version` and `event_hash` itself are deliberately excluded from that payload:
 * `event_hash` cannot hash itself, and `signature_key_version` is populated later, out of band, by
 * the (not yet built) periodic Merkle-checkpoint job once a WORM export target and KMS signing key
 * exist — seeing §4.8 in `docs/handoff/006-audit-chain.md` "What is NOT done". Because
 * `audit_events` is append-only, that job cannot `UPDATE` this column into an existing row either;
 * it is expected to write it on a separate table (`audit_checkpoints`, out of this task's scope)
 * that references a sequence range, not on the event row.
 */
final readonly class AuditChainService
{
    /**
     * Chain key used for events with no `subject_id` (system/actor-only events). A subject id is
     * always rendered as a UUID string (36 characters incl. hyphens), so this can never collide.
     */
    public const string SYSTEM_CHAIN_KEY = 'system';

    /**
     * `previous_hash` for the first event of a chain. SHA-256 never produces an all-zero digest
     * for real input (that would require finding a preimage of the zero hash, unsolved), so this
     * sentinel can never be confused with a genuine stored hash.
     */
    public const string GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {
    }

    /**
     * Canonical payload hash for callers that want the same encoding rule the chain itself uses
     * (`CanonicalJson` + SHA-256). Not mandatory — a caller hashing an already-serialized artifact
     * (e.g. a stored file, `BLUEPRINT.md` §4.9) computes that hash its own way and passes it directly
     * as `AuditEventInput::$payloadHash`.
     *
     * @param array<array-key, mixed> $payload
     */
    public function hashPayload(array $payload): string
    {
        return hash('sha256', CanonicalJson::encode($payload));
    }

    public function record(AuditEventInput $input): AuditEventRecord
    {
        $chainKey = $input->subjectId ?? self::SYSTEM_CHAIN_KEY;
        $connection = $this->connections->connection();

        return $connection->transaction(function () use ($connection, $input, $chainKey): AuditEventRecord {
            $head = $this->lockOrCreateHead($connection, $chainKey, $input->subjectId);

            $sequence = ((int) $head->last_sequence) + 1;
            $previousHash = (string) $head->last_event_hash;
            $id = Uuid::uuid7()->toString();
            $occurredAt = $input->occurredAt->setTimezone(new DateTimeZone('UTC'));

            $payload = AuditEventHasher::payload(
                id: $id,
                chainKey: $chainKey,
                sequence: $sequence,
                subjectId: $input->subjectId,
                actorId: $input->actorId,
                eventType: $input->eventType,
                resourceType: $input->resourceType,
                resourceId: $input->resourceId,
                occurredAt: $occurredAt,
                payloadHash: $input->payloadHash,
                previousHash: $previousHash,
            );

            $eventHash = AuditEventHasher::hash($payload, $previousHash);

            $connection->table('audit_events')->insert([
                'id' => $id,
                'chain_key' => $chainKey,
                'sequence' => $sequence,
                'subject_id' => $input->subjectId,
                'actor_id' => $input->actorId,
                'event_type' => $input->eventType,
                'resource_type' => $input->resourceType,
                'resource_id' => $input->resourceId,
                'occurred_at' => $payload['occurred_at'],
                'payload_hash' => $input->payloadHash,
                'previous_hash' => $previousHash,
                'event_hash' => $eventHash,
                'signature_key_version' => null,
            ]);

            $connection->table('audit_chain_heads')
                ->where('chain_key', $chainKey)
                ->update([
                    'last_sequence' => $sequence,
                    'last_event_hash' => $eventHash,
                    'updated_at' => CarbonImmutable::now('UTC'),
                ]);

            return new AuditEventRecord(
                id: $id,
                chainKey: $chainKey,
                sequence: $sequence,
                subjectId: $input->subjectId,
                actorId: $input->actorId,
                eventType: $input->eventType,
                resourceType: $input->resourceType,
                resourceId: $input->resourceId,
                occurredAt: $occurredAt,
                payloadHash: $input->payloadHash,
                previousHash: $previousHash,
                eventHash: $eventHash,
                signatureKeyVersion: null,
            );
        });
    }

    /**
     * Locks (creating it first if necessary) the single `audit_chain_heads` row that serializes
     * appends to `$chainKey`. Must be called inside an already-open transaction — the lock is
     * released at COMMIT/ROLLBACK.
     *
     * Race handled: two transactions appending the very first event of a brand-new chain both
     * reach this method concurrently. `insertOrIgnore` compiles to Postgres
     * `INSERT ... ON CONFLICT DO NOTHING`; if transaction A has already inserted (but not yet
     * committed) the row, transaction B's `INSERT ... ON CONFLICT` blocks until A commits or rolls
     * back, then either finds nothing to do (A committed — B's own `SELECT ... FOR UPDATE` below
     * then reads and locks A's row) or proceeds to insert its own (A rolled back). No deadlock is
     * possible: at most one row is ever contended per chain, and neither transaction holds a
     * different chain's lock while waiting for this one.
     *
     * @return object{last_sequence: int, last_event_hash: string}
     */
    private function lockOrCreateHead(
        ConnectionInterface $connection,
        string $chainKey,
        ?string $subjectId,
    ): object {
        /** @var object{last_sequence: int, last_event_hash: string}|null $head */
        $head = $connection->table('audit_chain_heads')
            ->where('chain_key', $chainKey)
            ->lockForUpdate()
            ->first();

        if ($head !== null) {
            return $head;
        }

        $now = CarbonImmutable::now('UTC');

        $connection->table('audit_chain_heads')->insertOrIgnore([
            'chain_key' => $chainKey,
            'subject_id' => $subjectId,
            'last_sequence' => 0,
            'last_event_hash' => self::GENESIS_HASH,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var object{last_sequence: int, last_event_hash: string}|null $head */
        $head = $connection->table('audit_chain_heads')
            ->where('chain_key', $chainKey)
            ->lockForUpdate()
            ->first();

        if ($head === null) {
            // Unreachable under correct concurrency semantics: insertOrIgnore above guarantees a
            // row exists for $chainKey before this query runs. Guarded so a future change to this
            // method fails loudly instead of returning null silently.
            throw new RuntimeException("audit_chain_heads row for chain '{$chainKey}' vanished after insertOrIgnore.");
        }

        return $head;
    }
}
