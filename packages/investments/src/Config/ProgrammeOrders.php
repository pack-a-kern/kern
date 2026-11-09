<?php

declare(strict_types=1);

namespace Kern\Investments\Config;

use Kern\Investments\Enums\Programme;

/** How one programme's orders are found on the portal, as `InvestmentsConfig` validated it. */
final readonly class ProgrammeOrders
{
    public function __construct(
        public Programme $programme,
        public string $search,
        public int $firstYear,
        private string $titlePattern,
    ) {
    }

    /** Whether an act's title is this programme's, not one that only mentions it. */
    public function isTitled(string $title): bool
    {
        return preg_match($this->titlePattern, $title) === 1;
    }
}
