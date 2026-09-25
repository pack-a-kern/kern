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
 * `embedding` is deliberately **not** listed in `$fillable`/`$casts` — nothing in this module ever
 * reads a stored vector back into PHP as a list of floats (all vector arithmetic happens in SQL,
 * per `BLUEPRINT.md` §15.5 — this layer retrieves and cites, it does not compute). Writes go through
 * {@see \Kern\Legislation\Retrieval\Indexing\EmbedIndexedChunks}, which sets it via a raw
 * query builder update using {@see \Kern\Legislation\Retrieval\Embedding\EmbeddingVector::toPgvectorLiteral()}
 * — Eloquent's attribute system has no `vector` cast to round-trip, and none is needed.
 *
 * @property string $id
 * @property string $section_id
 * @property int $chunk_index
 * @property int $chunk_count
 * @property string $text
 * @property string $text_hash
 * @property string|null $embedding_model
 * @property int|null $embedding_dim
 * @property \Illuminate\Support\Carbon|null $embedded_at
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
        'embedding_model',
        'embedding_dim',
        'embedded_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'chunk_index' => 'integer',
        'chunk_count' => 'integer',
        'embedding_dim' => 'integer',
        'embedded_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<SourceSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(SourceSection::class, 'section_id');
    }
}
