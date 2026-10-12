<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Legislation\Database\Factories\IndexedChunkFactory;
use Kern\Legislation\Models\SourceSection;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * `ext_source_section_chunks` — see that migration's docblock for why this is deliberately not
 * denormalized (dates/tier are read via `JOIN`, never copied here) and why the embedding
 * dimension is a fixed, explicit placeholder.
 *
 * Named `IndexedChunk`, not `SourceSectionChunk`, specifically to avoid colliding with
 * {@see \Kern\Legislation\Chunking\SourceSectionChunk} — that class is a pure, transient
 * DTO produced by {@see \Kern\Legislation\Chunking\SourceSectionChunker}; this is the
 * persisted row {@see \Kern\Legislation\Retrieval\Indexing\IndexDocumentVersionForRetrieval}
 * writes from one. Two different things; two different names.
 *
 * A chunk carries **no vector and no embedding provenance of its own**. Both belong to its text:
 * `ext_chunk_embeddings` keys one vector per `text_hash`, and the 91 consolidations of a law that
 * repeat a provision unchanged all reach the same row. `text_hash` is therefore the interesting
 * column here — it is this row's claim on a vector, and what
 * {@see \Kern\Legislation\Retrieval\Query\HybridLegislationSearch} joins on.
 *
 * @property string $id
 * @property string $section_id
 * @property int $chunk_index
 * @property int $chunk_count
 * @property string $text
 * @property string $text_hash
 */
#[UseFactory(IndexedChunkFactory::class)]
final class IndexedChunk extends Model
{
    /** @use HasFactory<IndexedChunkFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    protected $table = 'ext_source_section_chunks';

    /** @var list<string> */
    protected $fillable = [
        'section_id',
        'chunk_index',
        'chunk_count',
        'text',
        'text_hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'chunk_index' => 'integer',
        'chunk_count' => 'integer',
    ];

    /**
     * @return BelongsTo<SourceSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(SourceSection::class, 'section_id');
    }
}
