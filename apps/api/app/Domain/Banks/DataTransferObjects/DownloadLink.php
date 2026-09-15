<?php

declare(strict_types=1);

namespace App\Domain\Banks\DataTransferObjects;

use DateTimeImmutable;

/** A file linked from a publisher's page, and the date that file is for. */
final readonly class DownloadLink
{
    public function __construct(
        public string $url,
        public DateTimeImmutable $publishedOn,
    ) {
    }
}
