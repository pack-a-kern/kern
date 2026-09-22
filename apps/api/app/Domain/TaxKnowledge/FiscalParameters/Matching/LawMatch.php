<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Matching;

/** A paragraph of the law that says a value: which, where, and which of the value's numbers it says. */
final readonly class LawMatch
{
    /** @param list<int> $says */
    public function __construct(
        public string $sectionId,
        public string $citation,
        public string $documentVersionId,
        public array $says,
    ) {
    }

    /** @return array{section_id: string, citation: string, document_version_id: string, says: list<int>} */
    public function toArray(): array
    {
        return ['section_id' => $this->sectionId, 'citation' => $this->citation, 'document_version_id' => $this->documentVersionId, 'says' => $this->says];
    }
}
