<?php

declare(strict_types=1);

namespace Kern\Legislation\Chunking;

use DateTimeImmutable;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Enums\TrustTier;

/**
 * One chunk of one section's text, carrying the full retrieval metadata alongside it — the unit
 * a future Milestone 11 embeds and indexes into Qdrant. This module only produces these; it does
 * not embed or index them (out of this task's scope, per its brief).
 */
final readonly class SourceSectionChunk
{
    public function __construct(
        public string $sectionId,
        public string $documentVersionId,
        public string $sourceDocumentId,
        public string $sourceCode,
        public TrustTier $trustTier,
        public ?string $issuer,
        public string $documentType,
        public SectionType $sectionType,
        public string $citationLabel,
        public ?DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public ?DateTimeImmutable $publicationDate,
        public ?int $pageStart,
        public ?int $pageEnd,
        public int $chunkIndex,
        public int $chunkCount,
        public string $text,
    ) {
    }
}
