<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Indexing;

use Illuminate\Support\Facades\DB;
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Legislation\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;
use RuntimeException;

/**
 * The embedding call as an explicit, separate step from
 * {@see IndexDocumentVersionForRetrieval} — text/metadata indexing never depends on an embedding
 * provider being configured; this class is the one and only place
 * `Kern\Legislation\Retrieval\Embedding\EmbeddingProvider::embed()` is called.
 *
 * **What this embeds is a text, not a chunk.** `ext_chunk_embeddings` holds one vector per distinct
 * text, keyed by its hash, and the chunks that share that text share the vector — which on this
 * corpus is most of them: Legea 227/2015 has 91 consolidations, each repeating some 7,000
 * provisions that did not change, so 583,082 chunks carry 15,582 distinct texts. A chunk is
 * "embedded" when its text has a row there; it owns no vector of its own, and a re-parse that
 * recreates every chunk row costs no embedding work at all.
 *
 * With `docs/STATUS.md` blocked item #3 unresolved, the container binds
 * `Kern\Legislation\Retrieval\Embedding\UnconfiguredEmbeddingProvider` by default, so
 * calling this in production today throws {@see EmbeddingProviderNotConfiguredException} —
 * exactly the same accepted, visible limitation shape
 * `Kern\Documents\ExternalAi\RequestExternalDocumentExtraction`'s docblock describes for its
 * own provider seam. A caller is expected to catch that specific exception and leave the affected
 * texts unembedded (lexically/fuzzy searchable, not semantically) rather than treat it as a
 * retryable error — this is a business-decision gap, not a transient failure.
 */
final readonly class EmbedIndexedChunks
{
    public function __construct(
        private EmbeddingProvider $provider,
    ) {
    }

    /**
     * Embeds up to `$limit` texts that have no vector yet, optionally scoped to the texts one
     * document version uses.
     *
     * The vector is written with the raw query builder — Eloquent has no `vector` cast, and
     * `EmbeddingVector::toPgvectorLiteral()` is a plain string Postgres resolves against the
     * column's type at the point of use (verified empirically, see
     * `docs/handoff/029-hybrid-retrieval.md`).
     *
     * @return int texts given a vector
     *
     * @throws EmbeddingProviderNotConfiguredException if no real provider is wired
     */
    public function __invoke(?string $documentVersionId = null, int $limit = 500): int
    {
        // Before the database is asked for work: the table that holds the vectors exists only
        // where pgvector does, and a caller that catches this already knows to carry on without
        // semantic retrieval. It used to be `modelIdentifier()` that failed first for the same
        // callers, incidentally, because it was asked too early — now that it is asked after the
        // call that answers it, the unavailability has to be stated on purpose.
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            throw EmbeddingProviderNotConfiguredException::noVectorStore($driver);
        }

        $texts = $this->pendingTexts($documentVersionId, $limit);

        if ($texts === []) {
            return 0;
        }

        $vectors = $this->provider->embed(array_values($texts));

        if (count($vectors) !== count($texts)) {
            throw new RuntimeException(sprintf(
                'EmbeddingProvider returned %d vectors for %d texts — provider contract violated.',
                count($vectors),
                count($texts),
            ));
        }

        // Asked after the call, never before: the name must be the one the service reported for
        // these very vectors. Asking first is what stored one model under two names — see the
        // migration `2026_10_12_140000_one_vector_per_distinct_chunk_text.php`.
        $modelIdentifier = $this->provider->modelIdentifier();
        $hashes = array_keys($texts);

        DB::transaction(function () use ($hashes, $vectors, $modelIdentifier): void {
            foreach ($vectors as $index => $vector) {
                DB::insert(
                    'INSERT INTO ext_chunk_embeddings (text_hash, embedding_model, embedding_dim, embedding)
                          VALUES (?, ?, ?, ?::vector)
                     ON CONFLICT (text_hash) DO NOTHING',
                    [$hashes[$index], $modelIdentifier, $vector->dimension(), $vector->toPgvectorLiteral()],
                );
            }
        });

        return count($vectors);
    }

    /**
     * The distinct texts this corpus holds that have no vector yet, hash => text.
     *
     * Distinct by hash rather than per chunk row: the 91st consolidation of a law asks for the
     * same provisions as the 90th, and sending each copy would be the exact waste this table
     * exists to prevent.
     *
     * @return array<string, string>
     */
    private function pendingTexts(?string $documentVersionId, int $limit): array
    {
        $query = DB::table('ext_source_section_chunks as c')
            ->select('c.text_hash', 'c.text')
            ->whereNotExists(function ($exists): void {
                $exists->select(DB::raw('1'))
                    ->from('ext_chunk_embeddings as e')
                    ->whereColumn('e.text_hash', 'c.text_hash');
            })
            ->distinct()
            ->limit($limit);

        if ($documentVersionId !== null) {
            $query->whereExists(function ($exists) use ($documentVersionId): void {
                $exists->select(DB::raw('1'))
                    ->from('ext_source_sections as s')
                    ->whereColumn('s.id', 'c.section_id')
                    ->where('s.document_version_id', $documentVersionId);
            });
        }

        $texts = [];

        foreach ($query->get() as $row) {
            $hash = is_scalar($row->text_hash ?? null) ? (string) $row->text_hash : '';
            $text = is_scalar($row->text ?? null) ? (string) $row->text : '';

            if ($hash !== '') {
                $texts[$hash] = $text;
            }
        }

        return $texts;
    }
}
