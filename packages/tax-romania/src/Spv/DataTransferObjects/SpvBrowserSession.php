<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\DataTransferObjects;

use DateTimeImmutable;

/** What `apps/spv-browser`'s `POST /v1/spv/sessions` returns. */
final readonly class SpvBrowserSession
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
