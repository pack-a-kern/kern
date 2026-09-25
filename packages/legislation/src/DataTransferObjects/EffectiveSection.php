<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

use Kern\Legislation\Enums\SectionType;

/**
 * One section returned by
 * {@see \Kern\Legislation\Services\LegislationTemporalQuery::sectionsInForceOn()} —
 * a DTO, never the {@see \Kern\Legislation\Models\SourceSection} model itself
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
