<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Settings\Services\AiProviderCatalogue;
use App\Domain\Settings\Services\ResolveAiProviderCredentials;
use App\Domain\StatementImport\DataTransferObjects\AiAnswer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;

/**
 * One structured question to the configured AI provider, for the statement import.
 *
 * ## Why not the document provider
 *
 * `OpenAiCompatibleDocumentAiProvider` asks one question — "read this document" — and everything
 * about it is shaped for that: a document id, page images, an extraction schema, a
 * `DocumentExtraction` row to store the answer in. The import asks two completely different ones
 * ("which column is which", "what is this counterparty") about data that is not a document, and
 * answers nothing to store as a reading.
 *
 * What the two share is the *transport*, and that is shared: the provider settings, the dialect, the
 * timeout and the token ceiling all come from `AiProviderCatalogue`, so switching provider switches
 * both.
 *
 * ## Refuses before it sends
 *
 * No consent, or AI turned off, means no call and no exception — {@see ask()} answers a failed
 * {@see AiAnswer} and the screens fall back to doing the same work by hand. That is deliberate: an
 * import must never depend on a model being available.
 *
 * ## Which model
 *
 * The caller names one. Reading a statement's structure is a reasoning job and reading a document is
 * a vision job, and the best model for one is measurably bad at the other — so the import passes
 * `statement_import.ai.model` rather than inheriting the document reader's. Null falls back to the
 * configured provider default.
 *
 * ## Never logs, never explains with content
 *
 * Nothing here writes what was sent or received. A failure carries a code and no detail: the caller
 * carries on without a suggestion, which is exactly what it does when a person has no consent.
 */
final readonly class StatementAiClient
{
    public function __construct(
        private HttpFactory $http,
        private AiProviderCatalogue $catalogue,
        private ResolveAiProviderCredentials $credentials,
        private DocumentAiConsentGate $consent,
    ) {
    }

    /** Whether asking is possible at all: consent given, a provider configured and enabled. */
    public function isAvailable(string $subjectId): bool
    {
        if (! $this->consent->isConsented($subjectId)) {
            return false;
        }

        $credentials = ($this->credentials)();

        return $credentials->enabled && $credentials->baseUrl !== null && $credentials->model !== null;
    }

    /**
     * Ask one question and get the JSON object back, or null.
     *
     * @param  array<string, mixed>  $payload  what the model is given — masked by the caller
     * @param  array<string, mixed>  $schema  the JSON schema the reply must satisfy
     * @param  string|null  $model  which model to ask; null uses the provider's configured one
     * @param  int|null  $maxTokens  room for the reply; null uses the provider's own ceiling
     */
    public function ask(
        string $subjectId,
        string $systemPrompt,
        string $instruction,
        array $payload,
        array $schema,
        ?string $model = null,
        ?int $maxTokens = null,
    ): AiAnswer {
        if (! $this->isAvailable($subjectId)) {
            return AiAnswer::failed(AiAnswer::UNAVAILABLE);
        }

        $credentials = ($this->credentials)();
        $baseUrl = $credentials->baseUrl;
        $model = $model !== null && $model !== '' ? $model : $credentials->model;

        if ($baseUrl === null || $model === null) {
            return AiAnswer::failed(AiAnswer::UNAVAILABLE);
        }

        $dialect = $this->catalogue->dialectFor($credentials->provider);

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return AiAnswer::failed(AiAnswer::UNREADABLE);
        }

        $body = array_merge(
            $dialect->extractionBody(
                model: $model,
                systemPrompt: $systemPrompt,
                instruction: $instruction,
                // The question travels as the "document": one JSON object of headers and shapes, or
                // of masked counterparty names. No images, no attachments — there is no file here.
                documentText: $encoded,
                images: [],
                maxTokens: $maxTokens ?? $this->catalogue->extractionMaxTokens(),
            ),
            $dialect->jsonFormat($schema),
        );

        $headers = $credentials->apiKey === null
            ? $dialect->unauthenticatedHeaders($this->catalogue->anthropicVersion())
            : $dialect->authHeaders($credentials->apiKey, $this->catalogue->anthropicVersion());

        try {
            $response = $this->http
                ->timeout($this->catalogue->extractionTimeoutSeconds())
                ->withHeaders($headers)
                ->post(rtrim($baseUrl, '/') . $dialect->chatPath(), $body);
        } catch (ConnectionException) {
            // Never the transport's own message: it can embed the request URI, and this request
            // carries a person's counterparties.
            return AiAnswer::failed(AiAnswer::REFUSED);
        }

        if ($response->failed()) {
            return AiAnswer::failed(AiAnswer::REFUSED);
        }

        /** @var array<string, mixed>|null $json */
        $json = $response->json();
        $reply = data_get($json, $dialect->replyTextPath());

        if (! is_string($reply) || trim($reply) === '') {
            return AiAnswer::failed(self::ranOutOfRoom($json) ? AiAnswer::NO_ROOM : AiAnswer::UNREADABLE);
        }

        $decoded = $this->decode($reply);

        return $decoded === null ? AiAnswer::failed(AiAnswer::UNREADABLE) : AiAnswer::of($decoded);
    }

    /**
     * Whether an empty reply is a model that thought until its budget ran out.
     *
     * Two signs, because providers report it differently: a `length` finish reason, and reasoning
     * text present beside empty content. Only the shape of the response is read — never the
     * reasoning itself, which is as much a person's data as the answer would have been.
     *
     * @param  array<string, mixed>|null  $json
     */
    private static function ranOutOfRoom(?array $json): bool
    {
        if ($json === null) {
            return false;
        }

        foreach (['choices.0.finish_reason', 'stop_reason'] as $path) {
            if (in_array(data_get($json, $path), ['length', 'max_tokens'], true)) {
                return true;
            }
        }

        foreach (['choices.0.message.reasoning', 'choices.0.message.reasoning_content'] as $path) {
            $reasoning = data_get($json, $path);

            if (is_string($reasoning) && trim($reasoning) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The reply as an array.
     *
     * Models still fence JSON in markdown however firmly they are told not to, so one fence is
     * stripped before decoding — and anything that is not an object afterwards is discarded rather
     * than half-read.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $reply): ?array
    {
        $trimmed = trim($reply);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $trimmed) ?? $trimmed);
        }

        try {
            /** @var array<string, mixed>|scalar|null $decoded */
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
