<?php

declare(strict_types=1);

namespace Kern\Documents\Enums;

/**
 * `documents.ai_extraction_outcome` — what came of the one consent-gated external-AI reading
 * attempt (ADR 0005) made for this document, recorded so the answer is visible on the review
 * screen instead of only in a queue worker's log file.
 *
 * `null` means no attempt was made, which is the ordinary case for a document a deterministic
 * parser already read: `Kern\Documents\Jobs\ProcessScannedDocument` returns before the
 * external step whenever `Kern\Documents\Parsing\ProposeParsedExtraction` proposes something,
 * because a parser that recognises the layout beats a model and costs nothing.
 *
 * Closed and PII-free for the same reason as `RejectionReason`: this value is persisted, returned
 * over the API and rendered in the UI, so it must never carry provider response text — a failing
 * endpoint can echo back parts of what it was sent, and what it was sent is somebody's receipt
 * (`BLUEPRINT.md` §4.10).
 *
 * The cases are split as finely as the *remedies* differ, not as finely as the code paths do.
 * Every one of these tells the person in front of the screen something different to do about it:
 * grant consent, ask an administrator to enable AI, raise a token cap, or simply try again.
 */
enum AiExtractionOutcome: string
{
    /** The subject has not granted, or has revoked, ADR 0005 consent. Nothing was sent. */
    case NOT_SENT_CONSENT_REQUIRED = 'NOT_SENT_CONSENT_REQUIRED';

    /** No provider is bound at all — a deployment state, not a per-document one. Nothing was sent. */
    case NOT_SENT_PROVIDER_UNCONFIGURED = 'NOT_SENT_PROVIDER_UNCONFIGURED';

    /** An administrator has `ai.enabled` switched off. Nothing was sent. */
    case NOT_SENT_PROVIDER_DISABLED = 'NOT_SENT_PROVIDER_DISABLED';

    /**
     * There was nothing sendable: neither a text layer nor image bytes within the size ceiling.
     *
     * Recorded rather than sent, because a request carrying neither text nor picture asks a model
     * to read a document it was never given — it can only invent an answer, and inventing one is
     * the single worst outcome for a system whose whole premise is provenance.
     */
    case NOT_SENT_NOTHING_TO_SEND = 'NOT_SENT_NOTHING_TO_SEND';

    /** The endpoint could not be reached: DNS, TLS, connection refused, connect timeout. */
    case FAILED_UNREACHABLE = 'FAILED_UNREACHABLE';

    /**
     * The endpoint answered with an error status.
     *
     * Includes the case that prompted this enum: a reverse proxy in front of a self-hosted model
     * returning 504 because the read took longer than its own idle timeout, which is invisible
     * from inside the application and looks exactly like "nothing happened".
     */
    case FAILED_HTTP_STATUS = 'FAILED_HTTP_STATUS';

    /** A 200 whose body had no reply in it. */
    case FAILED_MALFORMED_REPLY = 'FAILED_MALFORMED_REPLY';

    /** A reasoning model spent its whole budget thinking. The remedy is a cap to raise. */
    case FAILED_REASONING_BUDGET = 'FAILED_REASONING_BUDGET';

    /** §14.4 doing its job: a reading missing a required field is discarded whole, never partially kept. */
    case FAILED_SCHEMA_VIOLATION = 'FAILED_SCHEMA_VIOLATION';

    /** A proposal was produced. Stored, rather than inferred from the existence of an extraction
     * row, so the column always describes the *latest* attempt — otherwise a stale failure would
     * outlive the successful retry that fixed it. */
    case SUCCEEDED = 'SUCCEEDED';

    /**
     * Whether this outcome represents something going wrong, as opposed to a configuration or
     * consent state that is working exactly as designed.
     *
     * Drives two decisions that should agree with each other: whether the pipeline reports the
     * exception to the log, and whether the UI presents this as a fault rather than as a setting
     * the person can change.
     */
    public function isFailure(): bool
    {
        return match ($this) {
            self::FAILED_UNREACHABLE,
            self::FAILED_HTTP_STATUS,
            self::FAILED_MALFORMED_REPLY,
            self::FAILED_REASONING_BUDGET,
            self::FAILED_SCHEMA_VIOLATION => true,
            default => false,
        };
    }
}
