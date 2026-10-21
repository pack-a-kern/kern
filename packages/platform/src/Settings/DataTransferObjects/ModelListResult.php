<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\DataTransferObjects;

/**
 * Outcome of asking a provider which models it serves. Failure is reported as a value rather than
 * an exception because "could not list models" is an ordinary, expected state — the endpoint is a
 * convenience, and an operator can always type a model name by hand.
 *
 * Three failures the screen may tell apart, because each is about this installation and not about
 * the address called: nothing is configured, the chosen provider is not one this installation knows,
 * or that provider publishes no model list. Everything that depends on what answered — unreachable,
 * a refused key, an error, an answer that is not a model list — is one `failed`, with the reason in
 * the log ({@see \Kern\Platform\Settings\Enums\AiProviderFailure}).
 */
final readonly class ModelListResult
{
    /**
     * @param  list<string>  $models
     */
    private function __construct(
        public bool $ok,
        public array $models,
        public ?string $reason,
    ) {
    }

    /**
     * @param  list<string>  $models
     */
    public static function success(array $models): self
    {
        return new self(true, $models, null);
    }

    public static function notConfigured(): self
    {
        return new self(false, [], 'not_configured');
    }

    public static function unknownProvider(): self
    {
        return new self(false, [], 'unknown_provider');
    }

    public static function notSupported(): self
    {
        return new self(false, [], 'not_supported');
    }

    public static function failed(): self
    {
        return new self(false, [], 'failed');
    }

    /**
     * @return array{ok: bool, models: list<string>, reason: string|null}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'models' => $this->models, 'reason' => $this->reason];
    }
}
