<?php

declare(strict_types=1);

namespace Kern\Audit;

/**
 * Why {@see \Kern\Audit\Services\ChainVerifier} stopped trusting a chain, reported against
 * the exact `sequence` where it happened (`docs/AGENTS.md` §6: enums for finite domain states).
 */
enum ChainVerificationFailureReason: string
{
    /** The chain has no head row at all — nothing was ever appended for this key. */
    case CHAIN_NOT_FOUND = 'CHAIN_NOT_FOUND';

    /** `sequence` jumps by more than 1 — a row is missing between two stored events. */
    case SEQUENCE_GAP = 'SEQUENCE_GAP';

    /** The same `sequence` appears twice in the same chain. */
    case DUPLICATE_SEQUENCE = 'DUPLICATE_SEQUENCE';

    /** The chain does not start at sequence 1. */
    case SEQUENCE_DOES_NOT_START_AT_ONE = 'SEQUENCE_DOES_NOT_START_AT_ONE';

    /** Stored `previous_hash` does not equal the previous event's stored `event_hash`. */
    case PREVIOUS_HASH_MISMATCH = 'PREVIOUS_HASH_MISMATCH';

    /**
     * Recomputing `SHA256(canonical_json(event_without_hash) || previous_hash)` from the row's
     * own stored columns does not equal its stored `event_hash` — the row's content changed.
     */
    case EVENT_HASH_MISMATCH = 'EVENT_HASH_MISMATCH';

    /** The head row's `last_sequence`/`last_event_hash` do not match the last stored event. */
    case HEAD_DOES_NOT_MATCH_LAST_EVENT = 'HEAD_DOES_NOT_MATCH_LAST_EVENT';
}
