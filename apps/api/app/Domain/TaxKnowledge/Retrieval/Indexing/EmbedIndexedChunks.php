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
 * {@see IndexSourceSectionForRetrieval} — text/metadata indexing never depends on an embedding
 * provider being configured; this class is the one and only place
 * `App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider::embed()` is called.
 *
 * With `docs/STATUS.md` blocked item #3 unresolved, the container binds
 * `App\Domain\TaxKnowledge\Retrieval\Embedding\UnconfiguredEmbeddingProvider` by default, so
 * calling this in production today throws {@see EmbeddingProviderNotConfiguredException} —
 * exactly the same accepted, visible limitation shape
 * `App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction`'s docblock describes for its
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
     * @throws EmbeddingProviderNotConfiguredException if no real provider is wired
     */
    public function __invoke(?string $documentVersionId = null, int $limit = 500): int
    {
        $query = IndexedChunk::query()->whereNull('embedded_at');

        if ($documentVersionId !== null) {
            $query->whereHas('section', function ($sectionQuery) use ($documentVersionId): void {
                $sectionQuery->where('document_version_id', $documentVersionId);
            });
        }

        /** @var \Illuminate\Support\Collection<int, IndexedChunk> $chunks */
        $chunks = $query->orderBy('created_at')->limit($limit)->get();

        if ($chunks->isEmpty()) {
            return 0;
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

        $modelIdentifier = $this->provider->modelIdentifier();
        $embeddedAt = now('UTC');

        DB::transaction(function () use ($chunks, $vectors, $modelIdentifier, $embeddedAt): void {
            foreach ($chunks->values() as $index => $chunk) {
                $vector = $vectors[$index];

                DB::table('source_section_chunks')
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

        return $chunks->count();
    }
}
