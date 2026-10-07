<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\ValueObjects;

/**
 * Thresholds expressed as multiples of the minimum wage, and which one an income reaches.
 *
 * Two contributions work this way — the health contribution on other income and the pension contribution on
 * independent activity — with different multiples, different rates and the same arithmetic. Written twice it
 * drifted on the first edit, so it is written once.
 *
 * ## It is reached from inside rules, so it obeys their rules
 *
 * No HTTP, no database, no clock, no randomness, nothing cached. The purity scanner walks classes that implement
 * `TaxRuleContract` and this is not one, so nothing enforces that here — which is exactly why it is said out
 * loud. Anything impure put in this file would be impurity a rule could reach and no test would catch.
 */
final readonly class WageThresholds
{
    /**
     * The multiples in ascending order, from a parameter's `{"wages": [...]}`.
     *
     * Sorted rather than trusted: the parameter is a JSON array somebody approved, and "the last one that fits"
     * is only the highest if the list is ordered. Non-integers and non-positive values are dropped rather than
     * coerced — a threshold of zero would make every income reach one.
     *
     * @param  array<string, mixed>  $valueJson
     * @return list<int>
     */
    public static function ascendingFrom(array $valueJson): array
    {
        $wages = $valueJson['wages'] ?? [];
        $multiples = [];

        foreach (is_array($wages) ? $wages : [] as $wage) {
            if (is_int($wage) && $wage > 0) {
                $multiples[] = $wage;
            }
        }

        sort($multiples);

        return $multiples;
    }

    /**
     * The highest threshold an income reaches, as money — plus which multiple that was, and all of them.
     *
     * Ascending, so the last one that fits wins. None fitting is a real outcome rather than a failure to find
     * one: below the first threshold nothing is owed, and the caller needs to be able to say so.
     *
     * Every threshold comes back whether or not it was reached, because "you were under the first one" is only
     * useful alongside what the first one was — and that is what a person reading an explanation asks next.
     *
     * @param  list<int>  $wageMultiples
     * @return array{0: Money, 1: int|null, 2: array<string, string>}
     */
    public static function reach(Money $income, Money $minimumWage, array $wageMultiples): array
    {
        $base = Money::zero($income->currencyCode);
        $reached = null;
        $thresholds = [];

        foreach ($wageMultiples as $multiple) {
            $threshold = self::wagesToMoney($minimumWage, $multiple);
            $thresholds['wages_' . $multiple] = $threshold->toString();

            if (! $income->isLessThan($threshold)) {
                $base = $threshold;
                $reached = $multiple;
            }
        }

        return [$base, $reached, $thresholds];
    }

    /**
     * A whole number of minimum wages as money.
     *
     * By repeated addition rather than a decimal multiplication: the multiple is a count and the wage is an exact
     * amount in minor units, so their product is exact — there is no rounding decision to make, and asking for
     * one would imply there were.
     */
    public static function wagesToMoney(Money $minimumWage, int $multiple): Money
    {
        $total = Money::zero($minimumWage->currencyCode);

        for ($i = 0; $i < $multiple; $i++) {
            $total = $total->add($minimumWage);
        }

        return $total;
    }
}
