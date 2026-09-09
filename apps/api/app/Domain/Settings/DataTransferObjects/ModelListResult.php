<?php

declare(strict_types=1);

namespace App\Domain\Settings\DataTransferObjects;

/**
 * Outcome of asking a provider which models it serves. Failure is reported as a value rather than
 * an exception because "could not list models" is an ordinary, expected state — the endpoint is a
 * convenience, and an operator can always type a model name by hand.
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

    public static function failure(string $reason): self
    {
        return new self(false, [], $reason);
    }

    /**
     * @return array{ok: bool, models: list<string>, reason: string|null}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'models' => $this->models, 'reason' => $this->reason];
    }
}
