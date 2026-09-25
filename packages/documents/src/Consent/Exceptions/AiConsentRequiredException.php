<?php

declare(strict_types=1);

namespace Kern\Documents\Consent\Exceptions;

use Kern\Documents\Enums\AiExtractionOutcome;
use Kern\Documents\ExternalAi\ExplainsAiExtractionOutcome;
use RuntimeException;

/**
 * The subject has not granted (or has revoked) consent for external document-AI processing
 * (ADR 0005). Thrown before any external call is even attempted — a subject who declines consent
 * gets a real, visible limitation (`BLUEPRINT.md` §14 pipeline stalls at this point for a document
 * that needs OCR/AI extraction), never a silent send anyway.
 */
final class AiConsentRequiredException extends RuntimeException implements ExplainsAiExtractionOutcome
{
    public static function forSubject(string $subjectId): self
    {
        return new self("Tax subject \"{$subjectId}\" has not granted consent for external document-AI processing.");
    }

    public function outcome(): AiExtractionOutcome
    {
        return AiExtractionOutcome::NOT_SENT_CONSENT_REQUIRED;
    }
}
