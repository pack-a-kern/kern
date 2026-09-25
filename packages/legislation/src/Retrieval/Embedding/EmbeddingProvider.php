<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Embedding;

use Kern\Legislation\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;

/**
 * The embedding call as a seam (`docs/handoff/029-hybrid-retrieval.md`) — deliberately mirroring
 * `Kern\Documents\ExternalAi\ExternalDocumentAiProvider`'s exact shape: an interface, a
 * concrete `UnconfiguredEmbeddingProvider` bound by default (`docs/STATUS.md` blocked item #3 —
 * no AI provider chosen), and nothing that calls an external service. ADR 0007 already commits
 * this project's LLM/embedding traffic to the OpenAI-compatible wire protocol once a provider is
 * chosen; this interface is what that future concrete adapter implements. Building that adapter
 * now, with no config or provider decision to test it against, would be exactly the untested,
 * unreachable code `docs/CODE-QUALITY.md` §2 forbids — see the handoff note's "what plugging in a
 * real provider requires".
 *
 * **Note the asymmetry ADR 0006 records with ADR 0005**: the corpus this interface embeds is
 * `ext_source_sections`/`ext_source_section_chunks` text — public Romanian legislation, not PII. Sending
 * it to an external embedding provider is a materially lower-risk decision than ADR 0005's
 * consent-gated, per-document external send for user-submitted documents. That still does not
 * make this task's job "just call an API" — no provider is chosen, and inventing one would violate
 * `docs/AGENTS.md` §2.
 */
interface EmbeddingProvider
{
    /**
     * `$purpose` defaults to {@see EmbeddingPurpose::PASSAGE} because indexing is by far the more
     * common call and the corpus side is what every existing caller means. A search must pass
     * {@see EmbeddingPurpose::QUERY} explicitly — see that enum for why getting it wrong is a
     * silent quality loss rather than an error.
     *
     * @param list<string> $texts
     * @return list<EmbeddingVector> in the same order as $texts, one per input
     *
     * @throws EmbeddingProviderNotConfiguredException if no real provider is wired
     */
    public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::PASSAGE): array;

    /**
     * Names the concrete model/provider that produced (or would produce) a call's vectors —
     * stored per row as `ext_source_section_chunks.embedding_model`, the same per-result provenance
     * shape `document_extractions.model_provider`/`.model_name` already establishes (`BLUEPRINT.md`
     * §6.6, ADR 0007).
     */
    public function modelIdentifier(): string;
}
