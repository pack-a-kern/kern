<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Chunking;

use App\Domain\TaxKnowledge\Models\OfficialSource;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;

/**
 * Loads one document version's sections with their ancestry (version, document, official source),
 * assembles a {@see ChunkableSection} for each, and delegates to {@see SourceSectionChunker}. This is
 * the database-integrated half of chunking; the chunker itself stays pure and independently testable.
 *
 * A whole version at a time, the ancestry read once: a consolidation of the Codul fiscal is some
 * 7 000 sections, and reading the same version, document and source again for every one of them was
 * most of the time indexing took.
 *
 * A section is chunked as {@see SourceSection::fullText()} — its tables written out row by row — so a
 * figure that only appears in a table is still found. A section's own `effective_from`/`effective_until`
 * win over its version's when set — matching
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
    public function chunksForVersion(string $documentVersionId): array
    {
        $version = SourceDocumentVersion::query()->findOrFail($documentVersionId);
        $document = SourceDocument::query()->findOrFail($version->source_document_id);
        $source = OfficialSource::query()->findOrFail($document->official_source_id);

        $chunks = [];

        foreach (SourceSection::query()->where('document_version_id', $version->id)->orderBy('id')->lazy() as $section) {
            array_push($chunks, ...$this->chunker->chunk(new ChunkableSection(
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
                text: $section->fullText(),
            )));
        }

        return $chunks;
    }
}
