<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi;

use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderFailedException;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use App\Domain\Settings\Services\AiProviderCatalogue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;

/**
 * The one provider implementation, for every configured provider.
 *
 * It speaks no vendor's API directly: the path, the auth headers, the message shape and the
 * structured-output keys all come from `AiDialect`. That is ADR 0007's point — the abstraction is
 * the wire protocol, not a class hierarchy — so switching the dropdown from a self-hosted Ollama to
 * OpenAI, Anthropic or OpenRouter is a settings change, not a code change.
 *
 * ## What it refuses to do
 *
 * **Return a reading that does not satisfy the schema.** `BLUEPRINT.md` §14.4: invalid schema is a
 * failure, not a best-effort save. `response_format` is asked for where the dialect supports it and
 * trusted nowhere — the reply is validated locally, because a model that ignores the request fails
 * silently and Anthropic has no equivalent at all.
 *
 * **Claim a confidence it was not given.** These endpoints report no per-field certainty, so the
 * stored figure is a fixed, deliberately middling value: high enough not to bury a proposal,
 * low enough that nobody mistakes it for the parser's measured 0.95. Inventing a number here would
 * put a made-up quantity next to a real one on the review screen.
 *
 * The document itself never becomes an instruction — see `ExtractionPrompt` for the three things
 * that make that true, only one of which is the prompt.
 */
final readonly class OpenAiCompatibleDocumentAiProvider implements ExternalDocumentAiProvider
{
    /**
     * What a model's reading is worth before a human looks at it.
     *
     * Not measured — no endpoint here reports one — so it is a constant, and named rather than
     * inline so nobody mistakes it for something the provider said.
     */
    private const string UNMEASURED_CONFIDENCE = '0.6000';

    private const string SCHEMA_VERSION = '1';

    public function __construct(
        private HttpFactory $http,
        private AiProviderCatalogue $catalogue,
        private ExtractionPrompt $prompt,
        private ExtractionSchemaValidator $validator,
    ) {
    }

    public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
    {
        $credentials = $request->credentials;
        $baseUrl = $credentials->baseUrl;
        $model = $credentials->model;
        $type = $request->documentType;

        if ($baseUrl === null || $model === null || $type === null) {
            throw ExternalDocumentAiProviderNotConfiguredException::noProviderBound();
        }

        $schema = $this->validator->jsonSchema($type);

        if ($schema === null) {
            // No agreed field set for this document type, so there is nothing to ask for. Refusing
            // beats sending the document somewhere to answer a question nobody has defined.
            throw ExternalDocumentAiProviderNotConfiguredException::noProviderBound();
        }

        $dialect = $this->catalogue->dialectFor($credentials->provider);

        $body = array_merge(
            $dialect->extractionBody(
                model: $model,
                systemPrompt: $this->prompt->system(),
                instruction: $this->prompt->instruction($type),
                documentText: $request->extractedText?->reveal(),
                image: $request->content?->toDialectImage(),
                maxTokens: $this->catalogue->extractionMaxTokens(),
            ),
            $dialect->jsonFormat($schema),
        );

        $headers = $credentials->apiKey === null
            ? $dialect->unauthenticatedHeaders($this->catalogue->anthropicVersion())
            : $dialect->authHeaders($credentials->apiKey, $this->catalogue->anthropicVersion());

        try {
            $response = $this->http
                // Not the connection test's timeout: a ping costs one token, reading a photograph
                // on a self-hosted model can take a minute.
                ->timeout($this->catalogue->extractionTimeoutSeconds())
                ->withHeaders($headers)
                ->post(rtrim($baseUrl, '/') . $dialect->chatPath(), $body);
        } catch (ConnectionException) {
            // Never forward transport-exception text: it can embed the request URI, and this one
            // carries a document. The caller records a failure without it.
            throw ExternalDocumentAiProviderFailedException::unreachable();
        }

        if ($response->failed()) {
            throw ExternalDocumentAiProviderFailedException::status($response->status());
        }

        $body = $response->json();
        $reply = data_get($body, $dialect->replyTextPath());

        if (! is_string($reply)) {
            throw ExternalDocumentAiProviderFailedException::malformedReply();
        }

        // A reasoning model that ran out of budget mid-thought returns empty `content` with its
        // chain of thought in a non-standard `reasoning` field. That is a *ceiling* problem, not a
        // model that could not read the document, and the two need telling apart — otherwise the
        // fix looks like "try another model" when it is "raise AI_EXTRACTION_MAX_TOKENS".
        //
        // The reasoning text itself is never used as an answer: it is the model talking to itself
        // about someone's document, and treating it as data would be inventing a reading.
        if (trim($reply) === '' && is_array($body) && data_get($body, 'choices.0.message.reasoning') !== null) {
            throw ExternalDocumentAiProviderFailedException::exhaustedByReasoning();
        }

        $fields = $this->validator->validate($type, $this->decode($reply));

        if ($fields === null) {
            throw ExternalDocumentAiProviderFailedException::schemaViolation();
        }

        return new DocumentAiResponse(
            modelProvider: $credentials->provider ?? 'custom',
            modelName: $model,
            schemaVersion: self::SCHEMA_VERSION,
            result: $fields,
            confidenceDecimal: self::UNMEASURED_CONFIDENCE,
        );
    }

    /**
     * The reply as an array, tolerating the two things models do to JSON.
     *
     * Some wrap it in a ```json fence despite being told not to; some emit leading prose. Both are
     * formatting, not content, so the object is located rather than the whole reply rejected — but
     * nothing is *repaired*: if what is found is not an object, this returns empty and the schema
     * check turns that into a failure.
     *
     * @return array<array-key, mixed>
     */
    private function decode(string $reply): array
    {
        $trimmed = trim($reply);

        // A fenced block, with or without a language tag.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $trimmed, $match) === 1) {
            $trimmed = $match[1];
        } elseif (preg_match('/(\{.*\})/s', $trimmed, $match) === 1) {
            $trimmed = $match[1];
        }

        try {
            $decoded = json_decode($trimmed, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
