<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Chunking;

use App\Domain\TaxKnowledge\Exceptions\UnknownSourceSectionException;
use App\Domain\TaxKnowledge\Models\OfficialSource;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;

/**
 * Loads one `ext_source_sections` row and its full ancestry (version, document, official source),
 * assembles a {@see ChunkableSection}, and delegates to {@see SourceSectionChunker}. This is the
 * database-integrated half of chunking; the chunker itself stays pure and independently testable.
 *
 * A section's own `effective_from`/`effective_until` win over its version's when set — matching
 * {@see \App\Domain\TaxKnowledge\Services\LegislationTemporalQuery}'s own inheritance rule.
 */
final readonly class SourceSectionChunkAssembler
{
    public function __construct(
        private SourceSectionChunker $chunker,
    ) {
    }

    /**
     * @return list<SourceSectionChunk>
     */
    public function chunksFor(string $sectionId): array
    {
        /** @var SourceSection|null $section */
        $section = SourceSection::query()->find($sectionId);

        if ($section === null) {
            throw UnknownSourceSectionException::forId($sectionId);
        }

        /** @var SourceDocumentVersion $version */
        $version = SourceDocumentVersion::query()->findOrFail($section->document_version_id);

        /** @var SourceDocument $document */
        $document = SourceDocument::query()->findOrFail($version->source_document_id);

        /** @var OfficialSource $source */
        $source = OfficialSource::query()->findOrFail($document->official_source_id);

        $chunkable = new ChunkableSection(
            sectionId: $section->id,
            documentVersionId: $version->id,
            sourceDocumentId: $document->id,
            sourceCode: $source->code,
            trustTier: $source->trust_tier,
            issuer: $document->issuer,
            documentType: $document->document_type,
            sectionType: $section->section_type,
            citationLabel: $section->citation_label,
            effectiveFrom: ($section->effective_from ?? $version->effective_from)?->toDateTimeImmutable(),
            effectiveUntil: ($section->effective_until ?? $version->effective_until)?->toDateTimeImmutable(),
            publicationDate: $document->publication_date?->toDateTimeImmutable(),
            pageStart: $section->page_start,
            pageEnd: $section->page_end,
            text: $section->text,
        );

        return $this->chunker->chunk($chunkable);
    }
}
