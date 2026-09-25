<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kern\Platform\Settings\DataTransferObjects\ModelListResult;

/**
 * Asks a provider which models it actually serves, so the operator picks from a live list instead
 * of typing a name that may not exist on their account or their self-hosted runtime.
 *
 * Called with a draft `base_url`/`api_key` from the settings form — i.e. before anything is saved
 * — so the operator can see the model list while still deciding, rather than having to save an
 * unverified configuration first.
 *
 * The base URL is operator-supplied by design (a self-hosted Ollama or a corporate gateway has no
 * predictable host), so this is a deliberate, authenticated-administrator-only outbound request
 * and not an SSRF hole opened to end users: the route requires the `system.settings.manage` permission, and an
 * administrator who can set `ai.base_url` can already cause the same request via the connection
 * test. Nothing from the response reaches storage — only model identifiers are returned.
 */
final readonly class FetchAiProviderModels
{
    public function __construct(
        private AiProviderCatalogue $catalogue,
        private HttpFactory $http,
    ) {
    }

    public function __invoke(string $providerKey, string $baseUrl, ?string $apiKey): ModelListResult
    {
        $preset = $this->catalogue->find($providerKey);

        if ($preset === null) {
            return ModelListResult::failure('unknown_provider');
        }

        if ($preset->modelsPath === null) {
            return ModelListResult::failure('not_supported');
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
            return ModelListResult::failure('connection_failed');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ModelListResult::failure('authentication_failed');
        }

        if ($response->failed()) {
            return ModelListResult::failure('provider_error');
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ModelListResult::failure('invalid_response');
        }

        $models = $preset->modelsShape->extract($body);

        // An empty list is not an error at the transport level, but it is useless to the operator
        // and almost always means the endpoint answered with something other than a model list
        // (a proxy page, an Ollama with nothing pulled). Say so rather than showing "0 models".
        return $models === []
            ? ModelListResult::failure('no_models')
            : ModelListResult::success($models);
    }
}
