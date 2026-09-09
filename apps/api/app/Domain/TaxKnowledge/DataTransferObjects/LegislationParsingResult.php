<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\DataTransferObjects;

use App\Domain\TaxKnowledge\Enums\SourceDocumentVersionStatus;

/**
 * What {@see \App\Domain\TaxKnowledge\Services\LegislationSectionPersister::persist()} hands back
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
