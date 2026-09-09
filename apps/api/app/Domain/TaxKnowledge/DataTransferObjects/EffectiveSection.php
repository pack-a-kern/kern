<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\DataTransferObjects;

use App\Domain\TaxKnowledge\Enums\SectionType;

/**
 * One section returned by
 * {@see \App\Domain\TaxKnowledge\Services\LegislationTemporalQuery::sectionsInForceOn()} —
 * a DTO, never the {@see \App\Domain\TaxKnowledge\Models\SourceSection} model itself
 * (`docs/AGENTS.md` §6).
 */
final readonly class EffectiveSection
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public SectionType $sectionType,
        public string $citationLabel,
        public string $text,
        public string $textHash,
    ) {
    }
}
