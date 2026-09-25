<?php

declare(strict_types=1);

namespace Kern\Legislation\Chunking;

/**
 * Splits one section's text into chunks no longer than `$maxChunkChars`, each carrying the full
 * retrieval metadata (`BLUEPRINT.md` §15.3). Pure — no database, no HTTP, no embeddings, no vector
 * index (Milestone 11's job, explicitly out of this task's scope).
 *
 * Splits on sentence boundaries greedily packed toward `$maxChunkChars`, falling back to a hard
 * character split only for a single sentence longer than the limit. No overlap between chunks —
 * an overlap window is a retrieval-quality tuning decision with no consumer to tune it against
 * yet (Milestone 11 adds one when it exists).
 */
final readonly class SourceSectionChunker
{
    public function __construct(
        private int $maxChunkChars = 1500,
    ) {
    }

    /**
     * @return list<SourceSectionChunk>
     */
    public function chunk(ChunkableSection $section): array
    {
        $pieces = $this->splitText($section->text);
        $chunkCount = count($pieces);

        $chunks = [];

        foreach ($pieces as $index => $text) {
            $chunks[] = new SourceSectionChunk(
                sectionId: $section->sectionId,
                documentVersionId: $section->documentVersionId,
                sourceDocumentId: $section->sourceDocumentId,
                sourceCode: $section->sourceCode,
                trustTier: $section->trustTier,
                issuer: $section->issuer,
                documentType: $section->documentType,
                sectionType: $section->sectionType,
                citationLabel: $section->citationLabel,
                effectiveFrom: $section->effectiveFrom,
                effectiveUntil: $section->effectiveUntil,
                publicationDate: $section->publicationDate,
                pageStart: $section->pageStart,
                pageEnd: $section->pageEnd,
                chunkIndex: $index,
                chunkCount: $chunkCount,
                text: $text,
            );
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitText(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [''];
        }

        if (mb_strlen($text) <= $this->maxChunkChars) {
            return [$text];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ($current === '') {
                $current = $sentence;
            } elseif (mb_strlen($current) + 1 + mb_strlen($sentence) <= $this->maxChunkChars) {
                $current .= ' ' . $sentence;
            } else {
                $chunks[] = $current;
                $current = $sentence;
            }

            while (mb_strlen($current) > $this->maxChunkChars) {
                $chunks[] = mb_substr($current, 0, $this->maxChunkChars);
                $current = mb_substr($current, $this->maxChunkChars);
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
