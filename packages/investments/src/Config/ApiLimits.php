<?php

declare(strict_types=1);

namespace Kern\Investments\Config;

/** The read endpoints' bounds, as `InvestmentsConfig` validated them. */
final readonly class ApiLimits
{
    /**
     * @param int<1, 200> $pageSize
     * @param array{int, int} $reads each person's reads: attempts, and the seconds they are counted over
     * @param array{int, int} $refreshes each person's profiles read from BVB on request, likewise
     */
    public function __construct(
        public int $pageSize,
        public int $maxSpanDays,
        public int $profileRefreshHours,
        public array $reads,
        public array $refreshes,
    ) {
    }
}
