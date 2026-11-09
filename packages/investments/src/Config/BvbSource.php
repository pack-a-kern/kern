<?php

declare(strict_types=1);

namespace Kern\Investments\Config;

use DateTimeZone;

/** The Bucharest Stock Exchange's pages, as `InvestmentsConfig` validated them. */
final readonly class BvbSource
{
    public const string CODE = 'BVB';

    public const string SYMBOL = '{symbol}';

    public function __construct(
        public string $sharesUrl,
        public string $bondsUrl,
        public string $dividendsUrl,
        private string $instrumentUrl,
        public DateTimeZone $timezone,
    ) {
    }

    /** One instrument's page. The symbol is encoded: it is the only part of the address not ours. */
    public function instrumentUrl(string $symbol): string
    {
        return str_replace(self::SYMBOL, rawurlencode($symbol), $this->instrumentUrl);
    }
}
