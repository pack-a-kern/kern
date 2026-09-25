<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\DataTransferObjects;

/**
 * The observed state of one internal service. `detail` names what was actually checked, so an
 * operator reading "Degraded" can tell whether the probe reached the service at all.
 */
final readonly class ComponentHealth
{
    private function __construct(
        public string $key,
        public string $state,
        public ?string $detail,
    ) {
    }

    public static function healthy(string $key, ?string $detail = null): self
    {
        return new self($key, 'healthy', $detail);
    }

    public static function degraded(string $key, ?string $detail = null): self
    {
        return new self($key, 'degraded', $detail);
    }

    /** The service is not configured for this installation — not a failure. */
    public static function notConfigured(string $key): self
    {
        return new self($key, 'not_configured', null);
    }

    /**
     * @return array{key: string, state: string, detail: string|null}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'state' => $this->state, 'detail' => $this->detail];
    }
}
