<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Retrieval\Indexing;

use App\Domain\TaxKnowledge\Chunking\SourceSectionChunkAssembler;
use App\Domain\TaxKnowledge\Retrieval\Models\IndexedChunk;
use Illuminate\Support\Facades\DB;

/**
 * Turns one section's {@see \App\Domain\TaxKnowledge\Chunking\SourceSectionChunk} DTOs
 * (`SourceSectionChunkAssembler::chunksFor()`, M3) into persisted `ext_source_section_chunks` rows —
 * text and metadata only, no embedding. Embedding is a deliberately separate step
 * ({@see EmbedIndexedChunks}) — this action never calls
 * `App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider`, so lexical/fuzzy retrieval over
 * a section works immediately after indexing regardless of whether an embedding provider is ever
 * configured.
 *
 * **Re-indexing an already-indexed section replaces its whole chunk set**, inside one
 * transaction — the same "delete and recreate, never mutate in place" shape
 * `App\Domain\TaxKnowledge\Services\LegislationSectionPersister` already uses for re-parsing a
 * section's own text. Chunk boundaries can shift when `SourceSectionChunker`'s output changes (a
 * different section text, or a future chunker version), so there is no stable "this is chunk 3"
 * identity worth preserving across a re-index; the unique constraint on `(section_id,
 * chunk_index)` would reject an in-place upsert with a differing chunk count anyway.
 */
final readonly class IndexSourceSectionForRetrieval
{
    public function __construct(
        private SourceSectionChunkAssembler $assembler,
    ) {
    }

    /**
     * @return int the number of chunks now indexed for this section
     */
    public function __invoke(string $sectionId): int
    {
        $chunks = $this->assembler->chunksFor($sectionId);

        return DB::transaction(function () use ($sectionId, $chunks): int {
            IndexedChunk::query()->where('section_id', $sectionId)->delete();

            foreach ($chunks as $chunk) {
                IndexedChunk::query()->create([
                    'section_id' => $sectionId,
                    'chunk_index' => $chunk->chunkIndex,
                    'chunk_count' => $chunk->chunkCount,
                    'text' => $chunk->text,
                    'text_hash' => hash('sha256', $chunk->text),
                ]);
            }

            return count($chunks);
        });
    }
}
