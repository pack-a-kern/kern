<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services\Holdings;

use OverflowException;
use RuntimeException;

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

    /**
     * The largest amount a holding's figure may be, in minor units — `wallet.holdings.max_amount_minor`,
     * failing by name when it is not set.
     */
    /**
     * Whether `$units` at `$perUnit`, plus `$extraMinor`, stays within {@see ceiling()} — compared exactly,
     * before anything is turned into an integer that could not hold it.
     *
     * @param  numeric-string  $units
     * @param  numeric-string  $perUnit
     */
    public static function withinCeiling(string $units, string $perUnit, int $extraMinor = 0): bool
    {
        $exact = bcadd(bcmul(bcmul($units, $perUnit, self::SCALE), self::MINOR_PER_MAJOR, self::SCALE), (string) $extraMinor, self::SCALE);

        return bccomp($exact, (string) self::ceiling(), self::SCALE) <= 0;
    }

    public static function ceiling(): int
    {
        $ceiling = filter_var(config('wallet.holdings.max_amount_minor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($ceiling) ? $ceiling : throw new RuntimeException('WALLET_HOLDINGS_MAX_AMOUNT_MINOR must be a whole number of minor units.');
    }

    /**
     * Rounded half up to the minor unit. A figure past what an integer holds is refused, never cut down to
     * the largest one — which would be an amount nobody paid.
     *
     * @param numeric-string $exact a non-negative amount in minor units
     */
    public static function rounded(string $exact): int
    {
        $whole = bcadd($exact, '0.5', 0);

        if (bccomp($whole, (string) PHP_INT_MAX, 0) > 0) {
            throw new OverflowException('An amount past what an integer holds.');
        }

        return (int) $whole;
    }
}
