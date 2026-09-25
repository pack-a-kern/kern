<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\DataTransferObjects;

/**
 * The outcome of `Kern\Platform\Settings\Services\TestAiProviderConnection` — a real, useful answer
 * to "does this configuration actually work," never a boolean alone. `$error` is a closed,
 * stable machine-readable code a settings screen branches on — and is the *only* thing a UI should
 * render, translated on its own side.
 *
 * `$message` is a diagnostic detail for logs and API consumers, deliberately not localised: no
 * user-facing copy is written in code (`BLUEPRINT.md` §19.1), and a screen that prints it would be
 * showing English to a Romanian operator. Never carries the API key or any raw provider response
 * body (§4.10/§12).
 */
final readonly class ConnectionTestResult
{
    private function __construct(
        public bool $success,
        public ?string $error,
        public ?string $message,
        public ?int $status,
        public ?int $latencyMs,
    ) {
    }

    public static function success(int $latencyMs): self
    {
        return new self(true, null, null, null, $latencyMs);
    }

    public static function notConfigured(): self
    {
        return new self(
            false,
            'not_configured',
            'The AI provider is not configured (missing base URL or model, and no fallback is set).',
            null,
            null,
        );
    }

    public static function connectionFailed(string $message): self
    {
        return new self(false, 'connection_failed', $message, null, null);
    }

    public static function authenticationFailed(): self
    {
        return new self(false, 'authentication_failed', 'The provider rejected the credentials.', null, null);
    }

    public static function providerError(int $status): self
    {
        return new self(false, 'provider_error', sprintf('The provider returned HTTP %d.', $status), $status, null);
    }

    public static function invalidResponse(): self
    {
        return new self(
            false,
            'invalid_response',
            'The provider returned a response that does not match the expected chat-completions shape.',
            null,
            null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = ['success' => $this->success];

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        if ($this->message !== null) {
            $payload['message'] = $this->message;
        }

        if ($this->status !== null) {
            $payload['status'] = $this->status;
        }

        if ($this->latencyMs !== null) {
            $payload['latency_ms'] = $this->latencyMs;
        }

        return $payload;
    }
}
