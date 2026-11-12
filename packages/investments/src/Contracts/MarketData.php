<?php

declare(strict_types=1);

namespace Kern\Investments\Contracts;

use DateTimeImmutable;
use Kern\Investments\DataTransferObjects\InstrumentTerms;
use Kern\Investments\DataTransferObjects\LatestPrice;
use Kern\Investments\DataTransferObjects\PayoutTerms;

/**
 * What another package may know of the market: an instrument's terms, its payouts, its latest price —
 * as readonly values, never as this package's models (ADR 0025 §1). `kern/tax-romania` holds a person's
 * lots against these and computes their movements and value from them.
 *
 * Every question is asked for many instruments at once, so a page of holdings costs one query per
 * question, not one per holding.
 */
interface MarketData
{
    /**
     * @param  list<string>  $ids
     * @return array<string, InstrumentTerms> by instrument id; an id that is not an instrument is left out
     */
    public function instruments(array $ids): array;

    /**
     * The payouts of these instruments paid between the two dates, both included, earliest first.
     *
     * @param  list<string>  $instrumentIds
     * @return list<PayoutTerms>
     */
    public function payouts(array $instrumentIds, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Each instrument's price on the last trading day it has one.
     *
     * @param  list<string>  $instrumentIds
     * @return array<string, LatestPrice> by instrument id; one never priced is left out
     */
    public function latestPrices(array $instrumentIds): array;

    /**
     * The instruments whose terms were read from this law — a bond, from the order that issued it.
     *
     * @return list<string> their ids; none, empty
     */
    public function readFrom(string $documentId): array;
}
