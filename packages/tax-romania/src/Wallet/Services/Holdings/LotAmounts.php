<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services\Holdings;

/**
 * The arithmetic of a lot: units times a price per unit in minor units, and a share of an amount split
 * with the units it belongs to. With `ext-bcmath` at full precision, rounded once, half up, to the
 * minor unit — the rounding art. 13 (5) of a Tezaur prospectus states for the interest an investor is
 * paid, and the one every other amount here follows.
 */
final class LotAmounts
{
    /** Minor units in one major unit: both currencies the market's instruments are issued in, RON and EUR, have two decimals. */
    private const string MINOR_PER_MAJOR = '100';

    private const int SCALE = 15;

    /**
     * @param  numeric-string  $units
     * @param  numeric-string  $perUnit  in the instrument's currency
     */
    public static function minor(string $units, string $perUnit): int
    {
        return self::rounded(bcmul(bcmul($units, $perUnit, self::SCALE), self::MINOR_PER_MAJOR, self::SCALE));
    }

    /**
     * The part of `$minor` that `$part` of `$whole` units carry — what moves with the units when a lot is
     * split.
     *
     * @param  numeric-string  $part
     * @param  numeric-string  $whole
     */
    public static function share(int $minor, string $part, string $whole): int
    {
        return self::rounded(bcdiv(bcmul((string) $minor, $part, self::SCALE), $whole, self::SCALE));
    }

    /** @param numeric-string $exact a non-negative amount in minor units */
    public static function rounded(string $exact): int
    {
        return (int) bcadd($exact, '0.5', 0);
    }
}
