<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\CandidateExtraction\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \App\Domain\TaxKnowledge\CandidateExtraction\CandidateCitationVerifier} when a
 * candidate change set references a `document_version_id` or citation `section_id` that does not
 * exist — a structurally valid but factually hallucinated reference.
 */
final class UnknownSourceReferenceException extends RuntimeException
{
    public static function unknownDocumentVersion(string $id): self
    {
        return new self(sprintf('candidate change set references unknown document_version_id "%s".', $id));
    }

    public static function unknownSection(string $id): self
    {
        return new self(sprintf('candidate change set cites unknown section_id "%s".', $id));
    }

    public static function sectionBelongsToDifferentVersion(string $sectionId, string $expectedVersionId, string $actualVersionId): self
    {
        return new self(sprintf(
            'cited section_id "%s" belongs to document_version_id "%s", not the referenced "%s".',
            $sectionId,
            $actualVersionId,
            $expectedVersionId,
        ));
    }
}
