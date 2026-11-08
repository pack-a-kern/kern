<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kern\Platform\Settings\DataTransferObjects\ModelListResult;
use Kern\Platform\Settings\Enums\AiProviderFailure;

/**
 * Asks a provider which models it actually serves, so the operator picks from a live list instead
 * of typing a name that may not exist on their account or their self-hosted runtime.
 *
 * Called with the saved address and the key saved alongside it, never a draft: see
 * `ListAiModelsController` for why. The address is operator-supplied by design (a self-hosted Ollama or
 * a corporate gateway has no predictable host), and its form is held to `AiProviderAddress`, so the
 * models path below is always the path called. Nothing from the response reaches storage — only model
 * identifiers are returned — and a failure says only that it failed ({@see AiProviderFailure}).
 */
final readonly class FetchAiProviderModels
{
    public function __construct(
        private AiProviderCatalogue $catalogue,
        private HttpFactory $http,
    ) {
    }

    /** `$operation` names the caller in the log: the model list itself, or the connection test asking it. */
    public function __invoke(string $providerKey, string $baseUrl, ?string $apiKey, string $operation = 'list-models'): ModelListResult
    {
        $preset = $this->catalogue->find($providerKey);

        if ($preset === null) {
            return ModelListResult::unknownProvider();
        }

        if ($preset->modelsPath === null) {
            return ModelListResult::notSupported();
        }

        $headers = $apiKey !== null && $apiKey !== ''
            ? $preset->dialect->authHeaders($apiKey, $this->catalogue->anthropicVersion())
            : $preset->dialect->unauthenticatedHeaders($this->catalogue->anthropicVersion());

        try {
            $response = $this->http
                ->timeout($this->catalogue->timeoutSeconds())
                ->withHeaders($headers)
                ->get(rtrim($baseUrl, '/') . $preset->modelsPath);
        } catch (ConnectionException) {
            // The exception message can embed the full request URI; per BLUEPRINT.md §4.10 no
            // transport-exception text is ever forwarded to an HTTP response.
            return $this->failed($operation, AiProviderFailure::UNREACHABLE);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return $this->failed($operation, AiProviderFailure::REJECTED_CREDENTIALS, $response->status());
        }

        if ($response->failed()) {
            return $this->failed($operation, AiProviderFailure::PROVIDER_ERROR, $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            return $this->failed($operation, AiProviderFailure::INVALID_RESPONSE);
        }

        $models = $preset->modelsShape->extract($body);

        // An empty list is not an error at the transport level, but it is useless to the operator
        // and almost always means the endpoint answered with something other than a model list
        // (a proxy page, an Ollama with nothing pulled). Say so rather than showing "0 models".
        return $models === []
            ? $this->failed($operation, AiProviderFailure::NO_MODELS)
            : ModelListResult::success($models);
    }

    private function failed(string $operation, AiProviderFailure $reason, ?int $httpStatus = null): ModelListResult
    {
        $reason->log($operation, $httpStatus);

        return ModelListResult::failed();
    }
}
