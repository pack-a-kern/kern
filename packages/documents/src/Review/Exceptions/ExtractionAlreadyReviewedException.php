<?php

declare(strict_types=1);

namespace Kern\Documents\Review\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Kern\Documents\Review\AcceptDocumentExtraction} or
 * {@see \Kern\Documents\Review\CorrectDocumentExtraction} when the target
 * `document_extractions` row is no longer `PROPOSED` — it has already been accepted, corrected
 * (and therefore rejected in favour of a newer row), or otherwise reviewed. Never names the
 * decision it already carries in the message; the caller already has the row and can inspect
 * `status` itself if it needs to.
 */
final class ExtractionAlreadyReviewedException extends RuntimeException
{
    public static function forExtraction(string $extractionId): self
    {
        return new self(sprintf(
            'Extraction "%s" has already been reviewed and can no longer be accepted or corrected.',
            $extractionId,
        ));
    }
}
