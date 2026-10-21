<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\DataTransferObjects;

/**
 * The outcome of `Kern\Platform\Settings\Services\TestAiProviderConnection`, as the screen sees it:
 * connected (and how quickly), not configured, or did not connect.
 *
 * No reason for a failure, on purpose — see {@see \Kern\Platform\Settings\Enums\AiProviderFailure},
 * which is where it goes instead. "Not configured" is the one distinct failure kept, because it
 * describes this installation's own settings and nothing about any address.
 */
final readonly class ConnectionTestResult
{
    private function __construct(
        public bool $success,
        public ?string $error,
        public ?int $latencyMs,
    ) {
    }

    public static function success(int $latencyMs): self
    {
        return new self(true, null, $latencyMs);
    }

    public static function notConfigured(): self
    {
        return new self(false, 'not_configured', null);
    }

    public static function failed(): self
    {
        return new self(false, 'failed', null);
    }

    /** @return array{success: bool, error?: string, latency_ms?: int} */
    public function toArray(): array
    {
        $payload = ['success' => $this->success];

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        if ($this->latencyMs !== null) {
            $payload['latency_ms'] = $this->latencyMs;
        }

        return $payload;
    }
}
