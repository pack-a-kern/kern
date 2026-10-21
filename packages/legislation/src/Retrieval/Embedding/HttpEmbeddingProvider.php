<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Embedding;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kern\Legislation\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;
use Kern\Platform\Config\RequiredConfig;
use Kern\Platform\Http\StackService;
use RuntimeException;
use Throwable;

/**
 * Embeds text through the `python-api` service, which runs `intfloat/multilingual-e5-small` locally
 * (`apps/processing/src/processing/embeddings`).
 *
 * The interface's original docblock anticipated an OpenAI-compatible external adapter once
 * `docs/STATUS.md` blocked item #3 was resolved. This implementation deliberately does not wait for
 * that decision: the model runs in our own container, so no corpus text and no user query crosses a
 * network boundary, and the provider/region/DPA question does not arise for retrieval at all. It
 * still must be resolved for document processing (ADR 0005), which sends taxpayer documents.
 *
 * Two properties this class is responsible for, both of which fail silently if unchecked:
 *
 *  - **Order.** The service returns vectors positionally. A reordered response would attach every
 *    section's meaning to a different section, and retrieval would still "work" — returning
 *    confident nonsense. The count is asserted; the order is the documented contract.
 *  - **Dimension.** The vector width must match `ext_source_section_chunks.embedding`'s `vector(N)`.
 *    Postgres rejects a wrong width on write, so this check is not what prevents corruption — it
 *    exists to fail with a sentence an operator can act on rather than a driver-level type error
 *    thousands of rows into a backfill.
 */
final class HttpEmbeddingProvider implements EmbeddingProvider
{
    /**
     * Set from the most recent successful response. Not a constructor value: the authority on what
     * produced a vector is the service that produced it, and this side must not record a model
     * name it merely configured.
     */
    private ?string $reportedModel = null;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly RequiredConfig $required,
    ) {
    }

    /**
     * @param  list<string>  $texts
     * @return list<EmbeddingVector>
     */
    public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::PASSAGE): array
    {
        if ($texts === []) {
            return [];
        }

        $service = StackService::processing($this->config);

        // Same failure this module already uses for "no provider": the caller is expected to leave
        // chunks unembedded rather than treat it as retryable.
        if (! $service->isConfigured()) {
            throw new EmbeddingProviderNotConfiguredException('The embedding service address is not configured.');
        }

        if (! $service->hasToken()) {
            throw new EmbeddingProviderNotConfiguredException(
                'The embedding service authentication token is not configured.',
            );
        }

        $vectors = [];

        foreach (array_chunk($texts, $this->batchSize()) as $batch) {
            foreach ($this->embedBatch($batch, $service, $purpose) as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    public function modelIdentifier(): string
    {
        /*
         * Only ever what the service reported, never what this side configured — and a caller who
         * asks before any call has been made is told so rather than given a guess.
         *
         * It used to fall back to `ai.embeddings.model`, and that was a bug with a visible result.
         * The service names itself `model_id@revision`, so the configured `EMBEDDING_MODEL_ID`
         * ("intfloat/multilingual-e5-small") is never the string it will report
         * ("intfloat/multilingual-e5-small@main"). `EmbedIndexedChunks` read this before embedding,
         * so each worker process stamped its first batch with the configured name and every later
         * batch with the real one: one model stored under two names, and 1,044 texts cached twice.
         * The provenance of a vector is not knowable before the vector exists, so this no longer
         * pretends otherwise.
         */
        if ($this->reportedModel === null) {
            throw new EmbeddingProviderNotConfiguredException(
                'No embedding call has been made yet, so no model has reported itself. '
                . 'Embed first, then record which model produced the vectors.',
            );
        }

        return $this->reportedModel;
    }

    /**
     * @param  list<string>  $batch
     * @return list<EmbeddingVector>
     */
    private function embedBatch(array $batch, StackService $service, EmbeddingPurpose $purpose): array
    {
        try {
            $response = $service->request($this->http, $this->required->positiveInt('ai.embeddings.timeout_seconds'))
                ->acceptJson()
                ->post($service->url('/v1/embeddings'), [
                    'texts' => $batch,
                    'input_type' => $purpose->value,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('The embedding service is unreachable.', previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('The embedding service answered ' . $response->status() . '.');
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();
        $rows = $body['vectors'] ?? null;
        $model = $body['model'] ?? null;

        if (! is_array($rows) || count($rows) !== count($batch)) {
            throw new RuntimeException(
                'The embedding service returned ' . (is_array($rows) ? count($rows) : 0)
                . ' vectors for ' . count($batch) . ' texts.',
            );
        }

        if (is_string($model) && $model !== '') {
            $this->reportedModel = $model;
        }

        $expected = $this->required->positiveInt('ai.embeddings.dimension');
        $vectors = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('The embedding service returned a malformed vector.');
            }

            $values = array_map(
                static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0,
                array_values($row),
            );

            if (count($values) !== $expected) {
                throw new RuntimeException(
                    'The embedding service returned ' . count($values)
                    . '-dimension vectors; this installation stores ' . $expected . '.',
                );
            }

            $vectors[] = new EmbeddingVector($values);
        }

        return $vectors;
    }

    /** @return int<1, max> */
    private function batchSize(): int
    {
        return max(1, $this->required->positiveInt('ai.embeddings.batch_size'));
    }
}
