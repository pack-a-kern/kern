<?php

declare(strict_types=1);

namespace Kern\Notifications\DataTransferObjects;

/** What a person recognises a device by: what it is, and the browser and system on it. */
final readonly class DeviceDescription
{
    public function __construct(
        public ?string $kind = null,
        public ?string $browser = null,
        public ?string $platform = null,
    ) {
    }
}
