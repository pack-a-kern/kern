<?php

declare(strict_types=1);

namespace Kern\Legislation\CandidateExtraction\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Kern\Legislation\CandidateExtraction\CandidateChangeSetParser} for any
 * structural violation of `BLUEPRINT.md` §9.2's contract — malformed JSON, a missing/wrong-typed
 * field, a candidate change with no citation, an out-of-range confidence, or an inconsistent
 * effective date range. `docs/AGENTS.md` §1.14 — "persist LLM output as truth without schema
 * validation" is forbidden — this exception is what makes that impossible: invalid output never
 * becomes a {@see \Kern\Legislation\CandidateExtraction\DataTransferObjects\CandidateChangeSet}.
 */
final class InvalidCandidateChangeSetException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('Invalid candidate change set: %s', $reason));
    }
}
