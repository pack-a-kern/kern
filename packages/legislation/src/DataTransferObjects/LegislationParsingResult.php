<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

use Kern\Legislation\Enums\SourceDocumentVersionStatus;

/**
 * What {@see \Kern\Legislation\Services\LegislationSectionPersister::persist()} hands back
 * — never the Eloquent models themselves (`docs/AGENTS.md` §6).
 */
final readonly class LegislationParsingResult
{
    public function __construct(
        public string $sourceDocumentVersionId,
        public int $sectionCount,
        public SourceDocumentVersionStatus $status,
        public ?string $publicationDate,
    ) {
    }
}
