<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

/** One act chosen from a portal search to be read: its type key, number, year, and its id on the portal. */
final readonly class PortalActToRead
{
    public function __construct(
        public string $type,
        public int $number,
        public int $year,
        public string $portalId,
    ) {
    }
}
