<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Embedding\Exceptions;

use RuntimeException;

/**
 * Thrown by `UnconfiguredEmbeddingProvider` — the concrete, container-bound answer to "no
 * embedding provider is configured" (`docs/STATUS.md` blocked item #3), mirroring
 * `Kern\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException`
 * exactly. A caller (`Kern\Legislation\Retrieval\Query\HybridLegislationSearch`,
 * `Kern\Legislation\Retrieval\Indexing\EmbedIndexedChunks`) catches this specific,
 * narrowly-typed exception to degrade to lexical-only retrieval or to leave chunks unembedded —
 * never a fallback that silently fabricates a vector.
 */
final class EmbeddingProviderNotConfiguredException extends RuntimeException
{
    public static function noProviderBound(): self
    {
        return new self(
            'No embedding provider is configured (docs/STATUS.md blocked item #3: external AI ' .
            'provider not yet chosen). Chunks cannot be embedded, and semantic retrieval is ' .
            'unavailable, until that decision is made.',
        );
    }

    /**
     * The other way semantic retrieval can be unavailable: a connection with no vector store.
     *
     * Vectors live in `ext_chunk_embeddings`, which `pgvector` backs and which the sqlite lane
     * therefore does not have at all (`docs/handoff/029-hybrid-retrieval.md`). A caller that
     * already degrades to lexical-only when no provider is configured should degrade identically
     * here rather than meet a missing-table error — the installation cannot do semantic retrieval
     * either way, and the reason is the same kind of reason.
     */
    public static function noVectorStore(string $driver): self
    {
        return new self(sprintf(
            'Semantic indexing needs a vector store, and the [%s] connection has none — '
            . '`ext_chunk_embeddings` is created on Postgres with pgvector only. Text and '
            . 'citation retrieval are unaffected.',
            $driver,
        ));
    }
}
