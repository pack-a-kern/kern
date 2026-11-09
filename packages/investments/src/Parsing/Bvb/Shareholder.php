<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

/** A line of a company's shareholder structure: a holder — or a group of them — its shares and its percent. */
final readonly class Shareholder
{
    public function __construct(
        public string $holder,
        public int $shares,
        public string $percent,
    ) {
    }
}
