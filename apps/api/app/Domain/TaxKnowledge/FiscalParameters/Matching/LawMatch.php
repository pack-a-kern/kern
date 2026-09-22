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
        /** The day the portal's note dates this wording from, when it is read in a later consolidation than the one in force. */
        public ?string $datedByNote = null,
        /** The day of the value's period the wording that says it came into force, when not its first. */
        public ?string $inForceFrom = null,
    ) {
    }

    public function inForceFrom(string $day): self
    {
        return new self($this->sectionId, $this->citation, $this->documentVersionId, $this->says, $this->datedByNote, $day);
    }

    /** @return array{section_id: string, citation: string, document_version_id: string, says: list<int>, dated_by_note?: string, in_force_from?: string} */
    public function toArray(): array
    {
        return [
            'section_id' => $this->sectionId,
            'citation' => $this->citation,
            'document_version_id' => $this->documentVersionId,
            'says' => $this->says,
            ...($this->datedByNote === null ? [] : ['dated_by_note' => $this->datedByNote]),
            ...($this->inForceFrom === null ? [] : ['in_force_from' => $this->inForceFrom]),
        ];
    }
}
