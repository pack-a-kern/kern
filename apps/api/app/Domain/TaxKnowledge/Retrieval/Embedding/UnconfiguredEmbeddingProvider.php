<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Retrieval\Embedding;

use App\Domain\TaxKnowledge\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;

/**
 * The container's default `EmbeddingProvider` binding (`App\Domain\TaxKnowledge\TaxKnowledgeServiceProvider`)
 * until a real provider is chosen — mirrors `App\Domain\Documents\ExternalAi\NullDocumentAiProvider`'s
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
