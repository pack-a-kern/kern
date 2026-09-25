<?php

declare(strict_types=1);

namespace Kern\Documents\ExternalAi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentContent;
use Kern\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderFailedException;
use Kern\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use Kern\Documents\Parsing\ExtractionSchema;
use Kern\Platform\Settings\Services\AiProviderCatalogue;

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

        if ($baseUrl === null || $model === null) {
            throw ExternalDocumentAiProviderNotConfiguredException::noProviderBound();
        }

        // The union, always: the model classifies on every read, so the reply may turn out to
        // describe any kind and the schema has to allow all of them.
        $schema = $this->validator->jsonSchema(null);

        $dialect = $this->catalogue->dialectFor($credentials->provider);

        $body = array_merge(
            $dialect->extractionBody(
                model: $model,
                systemPrompt: $this->prompt->system(),
                instruction: $this->prompt->instruction(),
                documentText: $request->extractedText?->reveal(),
                images: array_map(
                    static fn (DocumentContent $content): array => $content->toDialectImage(),
                    $request->contents,
                ),
                maxTokens: $this->catalogue->extractionMaxTokens(),
                attachments: $request->attachments,
            ),
            $dialect->jsonFormat($schema),
        );

        $headers = $credentials->apiKey === null
            ? $dialect->unauthenticatedHeaders($this->catalogue->anthropicVersion())
            : $dialect->authHeaders($credentials->apiKey, $this->catalogue->anthropicVersion());

        $startedAt = microtime(true);

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
            throw ExternalDocumentAiProviderFailedException::exhaustedByReasoning()
                ->withReply($this->replyForDiagnosis($body, $reply));
        }

        // Null, not the document's stored type: the reply says what the document is, every read.
        $extraction = $this->validator->validate(null, $this->decode($reply));

        if ($extraction === null) {
            throw ExternalDocumentAiProviderFailedException::schemaViolation()->withReply($reply);
        }

        return new DocumentAiResponse(
            modelProvider: $credentials->provider ?? 'custom',
            modelName: $model,
            schemaVersion: self::SCHEMA_VERSION,
            result: $extraction->fields,
            confidenceDecimal: $this->confidenceOf($extraction->fields),
            documentType: $extraction->type,
            rawReply: $reply,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            // The provider's own accounting, where it keeps any. A model that reports nothing leaves
            // these null rather than being given a guess that would read as measurement.
            promptTokens: $this->tokenCount($body, 'usage.prompt_tokens'),
            completionTokens: $this->tokenCount($body, 'usage.completion_tokens'),
        );
    }

    private function tokenCount(mixed $body, string $path): ?int
    {
        $value = data_get($body, $path);

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * The model's own confidence, or null when it did not report one.
     *
     * Null, not a placeholder. This used to return 0.6000 for a reading that said nothing, and the
     * review screen drew that as a 60% bar — the same thing it draws for a model that looked at a
     * blurred photograph and genuinely concluded 60%. A number nobody measured, shown as a
     * measurement, is worse than an empty space that says so.
     *
     * Clamped when it *is* reported, because a self-reported number is a claim rather than a
     * measurement and nothing downstream should have to defend against "1.4".
     *
     * @param  array<string, mixed>  $fields
     */
    private function confidenceOf(array $fields): ?string
    {
        $reported = $fields[ExtractionSchema::FIELD_CONFIDENCE] ?? null;

        if (! is_string($reported) || ! is_numeric($reported)) {
            return null;
        }

        return number_format(max(0.0, min(1.0, (float) $reported)), 4, '.', '');
    }

    /**
     * What to keep when a model produced nothing usable.
     *
     * An empty `content` with a `reasoning` field beside it is a model that spent its budget
     * thinking; the reasoning is the only thing that explains why, so it is what gets kept. It is
     * never treated as an answer — see the callers — only shown to a person asking what went wrong.
     */
    private function replyForDiagnosis(mixed $body, string $reply): string
    {
        $reasoning = is_array($body) ? data_get($body, 'choices.0.message.reasoning') : null;

        return trim($reply) !== '' || ! is_string($reasoning) ? $reply : $reasoning;
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
