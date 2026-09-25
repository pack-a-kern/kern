<?php

declare(strict_types=1);

namespace Kern\Documents\ExternalAi\Exceptions;

use Kern\Documents\Enums\AiExtractionOutcome;
use Kern\Documents\ExternalAi\ExplainsAiExtractionOutcome;
use RuntimeException;

/**
 * Nothing about this document could be sent: no text layer was extracted, and its bytes are either
 * not an image or larger than the configured ceiling.
 *
 * Deliberately not an `ExternalDocumentAiProviderFailedException` — the provider was never called,
 * and blaming it for a document that had nothing to offer would point an operator at the wrong
 * thing entirely.
 *
 * This condition used to be unrepresented: `readContent()` returned `null`, and the request went out
 * carrying neither text nor picture. A model asked to read a document it was never given cannot do
 * anything but invent an answer, which is the one failure mode a provenance system must never have.
 */
final class DocumentNotSendableForExtractionException extends RuntimeException implements ExplainsAiExtractionOutcome
{
    public static function nothingToSend(): self
    {
        return new self('The document has neither an extracted text layer nor sendable image content.');
    }

    public function outcome(): AiExtractionOutcome
    {
        return AiExtractionOutcome::NOT_SENT_NOTHING_TO_SEND;
    }
}
