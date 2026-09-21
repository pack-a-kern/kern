<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Retrieval\Indexing;

use App\Domain\TaxKnowledge\Chunking\SourceSectionChunkAssembler;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Retrieval\Models\IndexedChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rebuilds one document version's retrieval chunks: every one of its sections chunked, and the
 * chunks written in batches, replacing whatever the version had before — never appended to.
 *
 * The embedding is left empty here. {@see EmbedIndexedChunks} fills it afterwards — first by copying
 * the vector of an identical text already embedded, which is almost every chunk of a consolidation
 * that differs from the last one in a few provisions.
 */
final readonly class IndexDocumentVersionForRetrieval
{
    private const int INSERT_BATCH = 1000;

    public function __construct(
        private SourceSectionChunkAssembler $assembler,
    ) {
    }

    /** @return int the number of chunks written */
    public function __invoke(string $documentVersionId): int
    {
        $now = now('UTC');
        $rows = array_map(static fn ($chunk): array => [
            'id' => (string) Str::uuid7(),
            'section_id' => $chunk->sectionId,
            'chunk_index' => $chunk->chunkIndex,
            'chunk_count' => $chunk->chunkCount,
            'text' => $chunk->text,
            'text_hash' => hash('sha256', $chunk->text),
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->assembler->chunksForVersion($documentVersionId));

        DB::transaction(function () use ($documentVersionId, $rows): void {
            IndexedChunk::query()
                ->whereIn('section_id', SourceSection::query()->where('document_version_id', $documentVersionId)->select('id'))
                ->delete();

            foreach (array_chunk($rows, self::INSERT_BATCH) as $batch) {
                IndexedChunk::query()->insert($batch);
            }
        });

        return count($rows);
    }
}
