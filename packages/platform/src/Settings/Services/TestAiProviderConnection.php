<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kern\Platform\Settings\DataTransferObjects\ConnectionTestResult;
use Kern\Platform\Settings\Enums\AiProviderFailure;

/**
 * `POST /api/v1/settings/ai/test-connection` — actually calls the configured provider with a trivial
 * chat-completions request and reports whether it connected, per the task's explicit requirement:
 * "without this the user gets no feedback until a document silently fails hours later."
 *
 * **Only the saved address, with the key saved alongside it.** It used to take a draft address, and
 * fill in the stored key when none was typed — which sent the key to whatever address an
 * administrator named. Why a failure is reported without its reason is
 * {@see AiProviderFailure}'s docblock.
 *
 * Speaks the OpenAI-compatible `/v1/chat/completions` wire protocol `docs/adr/
 * 0007-provider-agnostic-ai.md` standardises on and `apps/processing/src/processing/ai/client.py`
 * already implements for the document pipeline's own eventual use — this is a separate, minimal
 * client for exactly one request shape (a one-token "ping"), not a duplicate of that module (no
 * apps/processing file is touched or imported; PHP cannot import Python code, and this task's
 * scope is `apps/api` only).
 *
 * ## Asked of the model list, not of the model
 *
 * Where the provider lists its models, the test asks that list and checks the configured model is
 * on it: the address answers, the key is accepted and the model exists — in the time a list takes.
 * A one-token chat request proved a little more at a great cost: a self-hosted runtime (Ollama)
 * loads the whole model into memory to say one token, and keeps it there afterwards, so pressing
 * "Test" took as long as a cold start and filled the GPU. Only a provider with no model list is
 * still asked the one-token question below.
 *
 * The request shape is chosen by the configured provider's `Kern\Platform\Settings\Enums\AiDialect`
 * rather than being fixed, because Anthropic's public API is not OpenAI-compatible: it takes
 * `/v1/messages` with an `x-api-key` header. Without that branch the Anthropic preset in
 * `config/ai/providers.php` would be an option that can never report a working connection.
 */
final readonly class TestAiProviderConnection
{
    public function __construct(
        private ResolveAiProviderCredentials $resolveCredentials,
        private AiProviderCatalogue $catalogue,
        private HttpFactory $http,
        private FetchAiProviderModels $models,
    ) {
    }

    public function __invoke(): ConnectionTestResult
    {
        $credentials = ($this->resolveCredentials)();

        $baseUrl = $credentials->baseUrl;
        $apiKey = $credentials->apiKey;
        $model = $credentials->model;

        if ($baseUrl === null || $model === null) {
            return ConnectionTestResult::notConfigured();
        }

        $provider = $credentials->provider ?? '';

        if ($this->catalogue->find($provider)?->modelsPath !== null) {
            return $this->listed($provider, $baseUrl, $apiKey, $model);
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
            // Never the underlying exception message: Guzzle/Http-client connection exceptions can
            // embed the request URI, and no transport-exception text is forwarded (BLUEPRINT.md §4.10).
            return $this->failed(AiProviderFailure::UNREACHABLE);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->status() === 401 || $response->status() === 403) {
            return $this->failed(AiProviderFailure::REJECTED_CREDENTIALS, $response->status());
        }

        if ($response->failed()) {
            return $this->failed(AiProviderFailure::PROVIDER_ERROR, $response->status());
        }

        $body = $response->json();
        $content = is_array($body) ? data_get($body, $dialect->replyTextPath()) : null;

        if (! is_string($content)) {
            return $this->failed(AiProviderFailure::INVALID_RESPONSE);
        }

        return ConnectionTestResult::success($latencyMs);
    }

    /** Connected when the provider lists the configured model; the list call says why when it did not. */
    private function listed(string $provider, string $baseUrl, ?string $apiKey, string $model): ConnectionTestResult
    {
        $startedAt = microtime(true);
        $list = ($this->models)($provider, $baseUrl, $apiKey, 'test-connection');
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $list->ok) {
            return ConnectionTestResult::failed();
        }

        return in_array($model, $list->models, true)
            ? ConnectionTestResult::success($latencyMs)
            : $this->failed(AiProviderFailure::MODEL_NOT_SERVED);
    }

    private function failed(AiProviderFailure $reason, ?int $httpStatus = null): ConnectionTestResult
    {
        $reason->log('test-connection', $httpStatus);

        return ConnectionTestResult::failed();
    }
}
