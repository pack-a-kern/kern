<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Enums;

/**
 * The HTTP wire format a provider speaks. `docs/adr/0007-provider-agnostic-ai.md` standardises on
 * the OpenAI-compatible shape, which is what almost every gateway and self-hosted runtime serves.
 * Anthropic's own public API is not that shape, so it gets the one exception rather than being
 * offered as a preset that could never connect.
 */
enum AiDialect: string
{
    case OPENAI = 'openai';
    case ANTHROPIC = 'anthropic';

    /** Path appended to `base_url` for a chat completion. */
    public function chatPath(): string
    {
        return match ($this) {
            self::OPENAI => '/v1/chat/completions',
            self::ANTHROPIC => '/v1/messages',
        };
    }

    /**
     * Headers carrying the API key. Anthropic authenticates with `x-api-key` and additionally
     * requires a pinned API version; OpenAI-compatible endpoints use a bearer token.
     *
     * @return array<string, string>
     */
    public function authHeaders(string $apiKey, string $anthropicVersion): array
    {
        return match ($this) {
            self::OPENAI => ['Authorization' => 'Bearer ' . $apiKey],
            self::ANTHROPIC => ['x-api-key' => $apiKey, 'anthropic-version' => $anthropicVersion],
        };
    }

    /**
     * Headers sent on a request that carries no API key. Anthropic still needs its version header
     * for the server to return a structured error rather than rejecting the request outright.
     *
     * @return array<string, string>
     */
    public function unauthenticatedHeaders(string $anthropicVersion): array
    {
        return match ($this) {
            self::OPENAI => [],
            self::ANTHROPIC => ['anthropic-version' => $anthropicVersion],
        };
    }

    /**
     * A minimal request body that costs one token, used by the connection test.
     *
     * @return array<string, mixed>
     */
    public function pingBody(string $model): array
    {
        return match ($this) {
            self::OPENAI => [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 1,
            ],
            self::ANTHROPIC => [
                'model' => $model,
                'max_tokens' => 1,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ],
        };
    }

    /**
     * A document-extraction request: the instructions, and the document itself.
     *
     * The two dialects attach a document differently — OpenAI-compatible endpoints take a content
     * *array* with an `image_url` holding a `data:` URI, Anthropic takes an `image` block with
     * base64 in `source` — so the shape is decided here rather than in the caller. A provider
     * implementation composes this and stays dialect-agnostic, which is what lets the same code
     * serve OpenAI, Anthropic, Ollama, OpenRouter and any other gateway from one dropdown
     * (`docs/adr/0007-provider-agnostic-ai.md`).
     *
     * A document can carry both text and pictures, because a document can be several files: a PDF
     * invoice with a photographed annex gives its text layer *and* the photographs. What is never
     * sent is two versions of the same file — a PDF part contributes its text or, if it has no text
     * layer, nothing, never both.
     *
     * Images follow the text, in part order, because that is the order the pages are in.
     *
     * **The document is data, never instructions** (`BLUEPRINT.md` §14.5). It is placed in a *user*
     * message, never the system one, and the system prompt says so explicitly — a receipt that
     * reads "ignore previous instructions" is a receipt, not a command.
     *
     * `temperature: 0` throughout: extraction has one right answer, and a figure that changes
     * between two runs of the same document is not one anybody can defend later.
     *
     * @param  list<array{mime_type: string, base64: string}>  $images  in part order
     * @param  list<array{filename: string, mime_type: string, base64: string}>  $attachments
     * @return array<string, mixed>
     */
    public function extractionBody(
        string $model,
        string $systemPrompt,
        string $instruction,
        ?string $documentText,
        array $images,
        int $maxTokens,
        array $attachments = [],
    ): array {
        $userContent = $this->documentContent($instruction, $documentText, $images);

        // A file the provider reads itself, rather than text we extracted for it. PDFs go this way
        // because a gateway that understands documents separates a statement's columns, which a
        // flat text layer does not — and a model given glued columns misreads which number is the
        // amount. Top-level, beside `messages`, per the gateway's own extension.
        $attached = $attachments === [] ? [] : ['attachments' => array_map(
            static fn (array $file): array => [
                'filename' => $file['filename'],
                'data' => "data:{$file['mime_type']};base64,{$file['base64']}",
            ],
            $attachments,
        )];

        return match ($this) {
            self::OPENAI => [
                'model' => $model,
                'temperature' => 0,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
                ...$attached,
            ],
            self::ANTHROPIC => [
                'model' => $model,
                'temperature' => 0,
                'max_tokens' => $maxTokens,
                // Anthropic carries the system prompt as a top-level field rather than a message.
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userContent]],
                ...$attached,
            ],
        };
    }

    /**
     * The user message carrying the document: the instruction, then its text, then its pictures.
     *
     * Text is fenced so the model can tell the instruction from the document — the boundary that
     * makes "this is data" enforceable rather than merely stated. A document with no text layer
     * still gets the instruction, and the pictures follow it either way.
     *
     * @param  list<array{mime_type: string, base64: string}>  $images
     * @return list<array<string, mixed>>
     */
    private function documentContent(string $instruction, ?string $documentText, array $images): array
    {
        $text = $documentText === null || $documentText === ''
            ? $instruction
            : $instruction . "\n\n<document>\n" . $documentText . "\n</document>";

        $content = [['type' => 'text', 'text' => $text]];

        foreach ($images as $image) {
            $content[] = $this->imageBlock($image);
        }

        return $content;
    }

    /**
     * One picture, in this dialect's shape.
     *
     * @param  array{mime_type: string, base64: string}  $image
     * @return array<string, mixed>
     */
    private function imageBlock(array $image): array
    {
        return match ($this) {
            // A `data:` URI rather than a link: the document is encrypted at rest and must never be
            // published somewhere a provider could fetch it from.
            self::OPENAI => [
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$image['mime_type']};base64,{$image['base64']}"],
            ],
            self::ANTHROPIC => [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mime_type'],
                    'data' => $image['base64'],
                ],
            ],
        };
    }

    /**
     * Request-body keys that ask the provider for JSON conforming to `$schema`.
     *
     * Structured output is the one place the dialects diverge more than cosmetically. OpenAI-
     * compatible endpoints take `response_format`; Ollama's façade understands the same key and
     * additionally accepts a bare `format`, so both are sent. Anthropic has no equivalent and is
     * steered by the prompt alone.
     *
     * None of this is trusted. `BLUEPRINT.md` §14.4 requires the *reply* to be validated against
     * the schema whatever the provider promised, because a model that ignores `response_format`
     * fails silently and a half-parsed receipt is worse than none.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function jsonFormat(array $schema): array
    {
        return match ($this) {
            self::OPENAI => [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'document_extraction', 'strict' => true, 'schema' => $schema],
                ],
                // Ollama serves an OpenAI-compatible surface but reads this key instead.
                'format' => 'json',
            ],
            self::ANTHROPIC => [],
        };
    }

    /**
     * Where the assistant's reply text sits in a successful response body. The connection test
     * asserts this is a string, so that a 200 carrying an unexpected shape (a captive portal, a
     * proxy error page) is reported as a failure rather than as a working provider.
     */
    public function replyTextPath(): string
    {
        return match ($this) {
            self::OPENAI => 'choices.0.message.content',
            self::ANTHROPIC => 'content.0.text',
        };
    }
}
