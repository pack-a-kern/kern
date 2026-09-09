<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\DataTransferObjects\ConnectionTestResult;
use App\Domain\Settings\DataTransferObjects\TestConnectionOverrides;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * `POST /api/v1/settings/ai/test-connection` — actually calls the configured (or, with
 * `$overrides`, a not-yet-saved draft) provider with a trivial chat-completions request and
 * reports success/failure with a useful, classified error, per the task's explicit requirement:
 * "without this the user gets no feedback until a document silently fails hours later."
 *
 * Speaks the OpenAI-compatible `/v1/chat/completions` wire protocol `docs/adr/
 * 0007-provider-agnostic-ai.md` standardises on and `apps/processing/src/processing/ai/client.py`
 * already implements for the document pipeline's own eventual use — this is a separate, minimal
 * client for exactly one request shape (a one-token "ping"), not a duplicate of that module (no
 * apps/processing file is touched or imported; PHP cannot import Python code, and this task's
 * scope is `apps/api` only).
 *
 * The request shape is chosen by the configured provider's `App\Domain\Settings\Enums\AiDialect`
 * rather than being fixed, because Anthropic's public API is not OpenAI-compatible: it takes
 * `/v1/messages` with an `x-api-key` header. Without that branch the Anthropic preset in
 * `config/ai_providers.php` would be an option that can never report a working connection.
 */
final readonly class TestAiProviderConnection
{
    public function __construct(
        private ResolveAiProviderCredentials $resolveCredentials,
        private AiProviderCatalogue $catalogue,
        private HttpFactory $http,
    ) {
    }

    public function __invoke(?TestConnectionOverrides $overrides = null): ConnectionTestResult
    {
        $credentials = ($this->resolveCredentials)();

        $baseUrl = $this->firstNonNull($overrides?->baseUrl, $credentials->baseUrl);
        $apiKey = $this->firstNonNull($overrides?->apiKey, $credentials->apiKey);
        $model = $this->firstNonNull($overrides?->model, $credentials->model);

        if ($baseUrl === null || $model === null) {
            return ConnectionTestResult::notConfigured();
        }

        $dialect = $this->catalogue->dialectFor($credentials->provider);
        $anthropicVersion = $this->catalogue->anthropicVersion();

        $headers = $apiKey !== null
            ? $dialect->authHeaders($apiKey, $anthropicVersion)
            : $dialect->unauthenticatedHeaders($anthropicVersion);

        $request = $this->http
            ->timeout($this->catalogue->timeoutSeconds())
            ->withHeaders($headers);

        $startedAt = microtime(true);

        try {
            $response = $request->post(
                rtrim($baseUrl, '/') . $dialect->chatPath(),
                $dialect->pingBody($model),
            );
        } catch (ConnectionException) {
            // Never include the underlying exception message here: Guzzle/Http-client connection
            // exceptions can embed the request URI including query strings, and — while base_url
            // is not itself secret — no code path in this class should ever get in the habit of
            // forwarding raw transport-exception text to an HTTP response (BLUEPRINT.md §4.10).
            return ConnectionTestResult::connectionFailed('Could not reach the configured base URL.');
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->status() === 401 || $response->status() === 403) {
            return ConnectionTestResult::authenticationFailed();
        }

        if ($response->failed()) {
            return ConnectionTestResult::providerError($response->status());
        }

        $body = $response->json();
        $content = is_array($body) ? data_get($body, $dialect->replyTextPath()) : null;

        if (! is_string($content)) {
            return ConnectionTestResult::invalidResponse();
        }

        return ConnectionTestResult::success($latencyMs);
    }

    private function firstNonNull(?string $override, ?string $fallback): ?string
    {
        return $override ?? $fallback;
    }
}
