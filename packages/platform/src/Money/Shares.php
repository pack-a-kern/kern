<?php

declare(strict_types=1);

namespace Kern\Platform\Money;

/**
 * Each part's share of the whole, for a screen that draws them side by side.
 *
 * A part below zero — an account whose records start after money was already in it — counts as none, in
 * the part and in the whole: a bar cannot be drawn shorter than nothing, and letting it reduce the whole
 * would draw the others larger than they are. Decimal strings, never floats, as every figure here is.
 */
final class Shares
{
    /** Decimals of a share: a hundredth of a percent. */
    private const int SCALE = 4;

    /**
     * @param  array<array-key, int>  $parts  in minor units of one currency
     * @return array<array-key, string> each part's share, by its key, "0" when there is no whole
     */
    public static function of(array $parts): array
    {
        $kept = array_map(static fn (int $part): int => max(0, $part), $parts);
        $whole = (string) array_sum($kept);

        return array_map(
            static fn (int $part): string => $whole === '0' ? '0' : bcdiv((string) $part, $whole, self::SCALE),
            $kept,
        );
    }

    /**
     * Each part against the largest of them, by size whatever its sign — the length of its bar when bars
     * share one scale rather than each filling its row.
     *
     * @param  array<array-key, int>  $parts
     * @return array<array-key, string>
     */
    public static function ofLargest(array $parts): array
    {
        $sizes = array_map(abs(...), $parts);
        $largest = (string) ($sizes === [] ? 0 : max($sizes));

        return array_map(
            static fn (int $size): string => $largest === '0' ? '0' : bcdiv((string) $size, $largest, self::SCALE),
            $sizes,
        );
    }

    /** `$part` of `$whole`, or null when there is no whole to be a part of. */
    public static function part(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : bcdiv((string) $part, (string) $whole, self::SCALE);
    }

    /** How far `$now` moved from `$before`, as a decimal of `$before` — null when there was nothing before. */
    public static function change(int $now, int $before): ?string
    {
        return self::part($now - $before, $before);
    }
}
