<?php

declare(strict_types=1);

namespace Kern\Legislation\Exceptions;

use DateTimeImmutable;
use RuntimeException;

/**
 * More than one `ext_source_document_versions` row claims an effective range covering the requested
 * date. This is a data-integrity defect, not something
 * {@see \Kern\Legislation\Services\LegislationTemporalQuery} may resolve by picking the
 * newest/most-specific/any other heuristic — `docs/AGENTS.md` §2 forbids guessing, and silently
 * picking one of two overlapping "authoritative" texts for the same date is exactly the kind of
 * guess that destroys this product's central guarantee (`BLUEPRINT.md` §39).
 */
final class AmbiguousEffectiveVersionException extends RuntimeException
{
    /**
     * @param list<string> $versionIds
     */
    public static function forDate(string $sourceDocumentId, DateTimeImmutable $date, array $versionIds): self
    {
        return new self(sprintf(
            'Overlapping effective ranges for source_document "%s" on %s: versions [%s]. Fix the data — never guess which applies.',
            $sourceDocumentId,
            $date->format('Y-m-d'),
            implode(', ', $versionIds),
        ));
    }
}
