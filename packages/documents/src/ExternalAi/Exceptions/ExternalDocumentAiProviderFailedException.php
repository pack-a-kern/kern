<?php

declare(strict_types=1);

namespace Kern\Documents\ExternalAi\Exceptions;

use Kern\Documents\Enums\AiExtractionOutcome;
use Kern\Documents\ExternalAi\ExplainsAiExtractionOutcome;
use RuntimeException;

/**
 * The provider was configured and reachable-in-principle, but this extraction did not produce a
 * usable reading.
 *
 * Distinct from `ExternalDocumentAiProviderNotConfiguredException`, which means nobody wired a
 * provider at all. That one is a deployment state; this is one document's attempt failing, and the
 * pipeline treats them differently — a missing provider is silence, a failed attempt is worth
 * recording so an operator can see the model is misbehaving.
 *
 * No message here carries provider response text or the request URI. A failing endpoint can echo
 * back parts of what it was sent, and what it was sent is somebody's receipt (`BLUEPRINT.md` §4.10).
 * The reason is a fixed phrase and, at most, an HTTP status.
 */
final class ExternalDocumentAiProviderFailedException extends RuntimeException implements ExplainsAiExtractionOutcome
{
    /**
     * Set by whichever named constructor built this instance, so the recorded outcome can never
     * drift from the message. A `match` on the message string would be the alternative, and that
     * is exactly the kind of thing that breaks silently when someone rewords a sentence.
     */
    private AiExtractionOutcome $outcome = AiExtractionOutcome::FAILED_UNREACHABLE;

    public static function unreachable(): self
    {
        $exception = new self('The AI provider could not be reached.');

        $exception->outcome = AiExtractionOutcome::FAILED_UNREACHABLE;

        return $exception;
    }

    public static function status(int $status): self
    {
        $exception = new self("The AI provider answered with HTTP {$status}.");

        $exception->outcome = AiExtractionOutcome::FAILED_HTTP_STATUS;

        return $exception;
    }

    /**
     * What the model said, when the failure is about what it said.
     *
     * Carried on the exception rather than logged: it is a reading of somebody's document, so it
     * belongs under their own encryption key on their own document row, not in a log file. Null for
     * the failures that happened before any reply existed — a timeout, a refused connection.
     */
    public ?string $rawReply = null;

    public function withReply(?string $reply): self
    {
        $this->rawReply = $reply;

        return $this;
    }

    public static function malformedReply(): self
    {
        $exception = new self('The AI provider returned a response with no readable reply.');

        $exception->outcome = AiExtractionOutcome::FAILED_MALFORMED_REPLY;

        return $exception;
    }

    /**
     * A reasoning model spent its whole token budget thinking and answered with nothing.
     *
     * Named apart from a schema violation because the remedy is completely different: this is a cap
     * to raise (`AI_EXTRACTION_MAX_TOKENS`), not a model that failed to read the document.
     */
    public static function exhaustedByReasoning(): self
    {
        $exception = new self('The AI model used its entire token budget reasoning and returned no answer.');

        $exception->outcome = AiExtractionOutcome::FAILED_REASONING_BUDGET;

        return $exception;
    }

    /** The §14.4 rule doing its job: a reading that misses a required field is discarded whole. */
    public static function schemaViolation(): self
    {
        $exception = new self('The AI provider returned data that does not satisfy the document schema.');

        $exception->outcome = AiExtractionOutcome::FAILED_SCHEMA_VIOLATION;

        return $exception;
    }

    public function outcome(): AiExtractionOutcome
    {
        return $this->outcome;
    }
}
