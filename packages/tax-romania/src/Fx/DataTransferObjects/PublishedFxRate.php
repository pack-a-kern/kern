<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\DataTransferObjects;

use DateTimeImmutable;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;

/**
 * A published rate without the document it was read from — what
 * {@see \Kern\TaxRomania\Fx\Services\FxRateQuery::latestRateOnOrBefore()} returns for a screen.
 *
 * A fiscal figure needs {@see FxRateWithProvenance}: the hash, path and fetch time of the BNR file.
 * A dashboard total needs the rate, its date and its source, and reading the rest cost three more
 * queries per currency on every page that converts.
 */
final readonly class PublishedFxRate
{
    public function __construct(
        public DecimalRate $perUnitRate,
        public string $currencyCode,
        public DateTimeImmutable $rateDate,
        public string $fxSourceCode,
    ) {
    }
}
