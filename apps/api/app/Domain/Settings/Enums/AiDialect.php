<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

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
