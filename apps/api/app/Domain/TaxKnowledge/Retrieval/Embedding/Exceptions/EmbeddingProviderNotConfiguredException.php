<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Retrieval\Embedding\Exceptions;

use RuntimeException;

/**
 * Thrown by `UnconfiguredEmbeddingProvider` — the concrete, container-bound answer to "no
 * embedding provider is configured" (`docs/STATUS.md` blocked item #3), mirroring
 * `Kern\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException`
 * exactly. A caller (`App\Domain\TaxKnowledge\Retrieval\Query\HybridLegislationSearch`,
 * `App\Domain\TaxKnowledge\Retrieval\Indexing\EmbedIndexedChunks`) catches this specific,
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
}
