<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Retrieval\Indexing;

use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider;
use App\Domain\TaxKnowledge\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;
use App\Domain\TaxKnowledge\Retrieval\Models\IndexedChunk;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The embedding call as an explicit, separate step from
 * {@see IndexDocumentVersionForRetrieval} — text/metadata indexing never depends on an embedding
 * provider being configured; this class is the one and only place
 * `App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider::embed()` is called.
 *
 * With `docs/STATUS.md` blocked item #3 unresolved, the container binds
 * `App\Domain\TaxKnowledge\Retrieval\Embedding\UnconfiguredEmbeddingProvider` by default, so
 * calling this in production today throws {@see EmbeddingProviderNotConfiguredException} —
 * exactly the same accepted, visible limitation shape
 * `Kern\Documents\ExternalAi\RequestExternalDocumentExtraction`'s docblock describes for its
 * own provider seam. A caller is expected to catch that specific exception and leave the affected
 * chunks unembedded (lexically/fuzzy searchable, not semantically) rather than treat it as a
 * retryable error — this is a business-decision gap, not a transient failure.
 */
final readonly class EmbedIndexedChunks
{
    public function __construct(
        private EmbeddingProvider $provider,
    ) {
    }

    /**
     * Embeds up to `$limit` chunks that have never been embedded, optionally scoped to one
     * document version. Rows are updated one write per chunk via the raw query builder — Eloquent
     * has no `vector` cast, and `EmbeddingVector::toPgvectorLiteral()` is a plain string Postgres
     * resolves against the column's type at the point of use (verified empirically, see
     * `docs/handoff/029-hybrid-retrieval.md`).
     *
     * A chunk whose exact text was already embedded by the same model is given that vector rather
     * than sent again — from `ext_chunk_embeddings`, which keeps every vector by its text's hash and
     * outlives the chunks themselves. Ninety consolidations of one law repeat almost every provision
     * unchanged, and a re-parse recreates every chunk: without it the law would go back to the model
     * ninety times over, and again after every parser change.
     *
     * @return int chunks given a vector, copied or computed
     *
     * @throws EmbeddingProviderNotConfiguredException if no real provider is wired
     */
    public function __invoke(?string $documentVersionId = null, int $limit = 500): int
    {
        $modelIdentifier = $this->provider->modelIdentifier();
        $copied = $this->copyIdentical($modelIdentifier);

        $query = IndexedChunk::query()->whereNull('embedded_at');

        if ($documentVersionId !== null) {
            $query->whereHas('section', function ($sectionQuery) use ($documentVersionId): void {
                $sectionQuery->where('document_version_id', $documentVersionId);
            });
        }

        /** @var \Illuminate\Support\Collection<int, IndexedChunk> $chunks */
        $chunks = $query->orderBy('created_at')->limit($limit)->get();

        if ($chunks->isEmpty()) {
            return $copied;
        }

        $texts = array_values($chunks->map(static fn (IndexedChunk $chunk): string => $chunk->text)->all());
        $vectors = $this->provider->embed($texts);

        if (count($vectors) !== count($texts)) {
            throw new RuntimeException(sprintf(
                'EmbeddingProvider returned %d vectors for %d texts — provider contract violated.',
                count($vectors),
                count($texts),
            ));
        }

        $embeddedAt = now('UTC');

        DB::transaction(function () use ($chunks, $vectors, $modelIdentifier, $embeddedAt): void {
            foreach ($chunks->values() as $index => $chunk) {
                $vector = $vectors[$index];

                DB::insert(
                    'INSERT INTO ext_chunk_embeddings (text_hash, embedding_model, embedding_dim, embedding) VALUES (?, ?, ?, ?::vector) ON CONFLICT DO NOTHING',
                    [$chunk->text_hash, $modelIdentifier, $vector->dimension(), $vector->toPgvectorLiteral()],
                );

                DB::table('ext_source_section_chunks')
                    ->where('id', $chunk->id)
                    ->update([
                        'embedding' => $vector->toPgvectorLiteral(),
                        'embedding_model' => $modelIdentifier,
                        'embedding_dim' => $vector->dimension(),
                        'embedded_at' => $embeddedAt,
                        'updated_at' => $embeddedAt,
                    ]);
            }
        });

        return $copied + $chunks->count();
    }

    /** Gives every unembedded chunk the vector its exact text already has, in one statement. */
    private function copyIdentical(string $modelIdentifier): int
    {
        return DB::update(
            <<<'SQL'
                UPDATE ext_source_section_chunks AS c
                SET embedding = e.embedding,
                    embedding_model = e.embedding_model,
                    embedding_dim = e.embedding_dim,
                    embedded_at = now(),
                    updated_at = now()
                FROM ext_chunk_embeddings AS e
                WHERE c.embedded_at IS NULL AND c.text_hash = e.text_hash AND e.embedding_model = ?
                SQL,
            [$modelIdentifier],
        );
    }
}
