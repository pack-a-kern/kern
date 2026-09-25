<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Embedding;

use Kern\Legislation\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;

/**
 * The container's default `EmbeddingProvider` binding (`Kern\Legislation\TaxKnowledgeServiceProvider`)
 * until a real provider is chosen — mirrors `Kern\Documents\ExternalAi\NullDocumentAiProvider`'s
 * exact reasoning: a concrete, named Null Object with an explicit, documented failure, rather than
 * leaving the interface unbound (which would fail with a generic `BindingResolutionException`).
 */
final class UnconfiguredEmbeddingProvider implements EmbeddingProvider
{
    public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::PASSAGE): array
    {
        throw EmbeddingProviderNotConfiguredException::noProviderBound();
    }

    public function modelIdentifier(): string
    {
        throw EmbeddingProviderNotConfiguredException::noProviderBound();
    }
}
