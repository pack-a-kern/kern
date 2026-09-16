<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Retrieval\Embedding;

use App\Domain\TaxKnowledge\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

/**
 * Embeds text through the `python-api` service, which runs `BAAI/bge-m3` locally
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

        $token = $this->stringConfig('services.processing.auth_token');

        if ($token === '') {
            // Same failure this module already uses for "no provider": the caller is expected to
            // leave chunks unembedded rather than treat it as retryable.
            throw new EmbeddingProviderNotConfiguredException(
                'The embedding service authentication token is not configured.',
            );
        }

        $vectors = [];

        foreach (array_chunk($texts, $this->batchSize()) as $batch) {
            foreach ($this->embedBatch($batch, $token, $purpose) as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    public function modelIdentifier(): string
    {
        // Prefer what the service reported over what this side configured: the string is stored per
        // row as `embedding_model`, and it must name what actually produced the vector. The
        // configured name is only a stand-in for callers that ask before any call has been made.
        return $this->reportedModel ?? $this->stringConfig('retrieval.embedding.model');
    }

    /**
     * @param  list<string>  $batch
     * @return list<EmbeddingVector>
     */
    private function embedBatch(array $batch, string $token, EmbeddingPurpose $purpose): array
    {
        try {
            $response = $this->http
                ->timeout($this->intConfig('retrieval.embedding.timeout_seconds'))
                ->withToken($token)
                ->acceptJson()
                ->post($this->baseUrl() . '/v1/embeddings', [
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

        $expected = $this->intConfig('retrieval.embedding.dimension');
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

    private function baseUrl(): string
    {
        return rtrim($this->stringConfig('services.processing.base_url'), '/');
    }

    /** @return int<1, max> */
    private function batchSize(): int
    {
        return max(1, $this->intConfig('retrieval.embedding.batch_size'));
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? $value : '';
    }

    /**
     * A positive whole number from config, or a refusal naming the key.
     *
     * No fallback of its own: this used to carry defaults that disagreed with config — a dimension of
     * 1024 here against 384 there — and a fallback that is never used is still read, and believed.
     */
    private function intConfig(string $key): int
    {
        $value = $this->config->get($key);

        if (! is_int($value) || $value <= 0) {
            throw new RuntimeException("Missing or invalid config value \"{$key}\".");
        }

        return $value;
    }
}
