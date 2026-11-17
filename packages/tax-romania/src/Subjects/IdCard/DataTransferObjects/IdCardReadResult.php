<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\IdCard\DataTransferObjects;

/**
 * The outcome of one read: either a {@see IdCardReading}, or a reason it could not be produced.
 *
 * The reasons are coarse on purpose. A caller — and in turn the person at the screen — is told "the
 * card could not be read, type it by hand", never why in a way that echoes a provider's own message
 * (which can embed the request URL, and this request carried a card: BLUEPRINT §4.10).
 */
final readonly class IdCardReadResult
{
    /** No provider is configured or enabled, or it has no usable model. */
    public const string UNAVAILABLE = 'UNAVAILABLE';

    /** The provider could not be reached or refused the request. */
    public const string REFUSED = 'REFUSED';

    /** The reply was empty or not the JSON that was asked for. */
    public const string UNREADABLE = 'UNREADABLE';

    /** The upload was no longer in the scratch store when the worker looked (expired or already read). */
    public const string EXPIRED = 'EXPIRED';

    private function __construct(
        public ?IdCardReading $reading,
        public ?string $failure,
    ) {
    }

    public static function of(IdCardReading $reading): self
    {
        return new self($reading, null);
    }

    public static function failed(string $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->reading !== null;
    }

    /**
     * The browser-facing shape, stored (encrypted) in the scratch store and returned verbatim by
     * the fetch endpoint.
     *
     * @return array{status: string, reading?: array<string, mixed>, reason?: string}
     */
    public function toArray(): array
    {
        if ($this->reading !== null) {
            return ['status' => 'ready', 'reading' => $this->reading->toArray()];
        }

        return ['status' => 'failed', 'reason' => $this->failure ?? self::UNREADABLE];
    }
}
