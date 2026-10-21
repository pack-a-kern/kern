<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services\Fx;

use DateTimeImmutable;
use Kern\Platform\Money\CurrencyCode;
use Kern\TaxRomania\Fx\Services\FxRateQuery;
use Kern\TaxRomania\Wallet\DataTransferObjects\ConvertedTotal;

/**
 * Adds per-currency wallet totals into one, at the latest published rate, for display.
 *
 * ## What it is not
 *
 * Not a fiscal conversion. `BLUEPRINT.md` §7.4 requires every `TaxRule` to pick its own method —
 * spot, monthly average, annual average — and record the rate, date and source alongside the
 * result. This class picks one method for a dashboard and says so loudly: the rates it used come
 * back with the total, and nothing here is ever persisted.
 *
 * It exists because "€444.00 · RON 600,010.00" answers no question a person actually has. "Roughly
 * how much do I have" is a real question, and it needs one number.
 *
 * ## Cross-rates go through RON
 *
 * The BNR publishes rates *as RON per unit of foreign currency*, so RON is the hinge: an amount in
 * X becomes `amount x rate(X)` RON, and RON becomes the display currency by dividing by
 * `rate(display)`. Converting EUR to USD directly would need a rate the BNR never published, and
 * inventing one is exactly the kind of quiet fabrication this codebase refuses elsewhere.
 *
 * ## A missing rate omits a currency; it never guesses one
 *
 * The imported rate history has gaps, and a currency the BNR does not publish at all is possible.
 * Such an amount is left out of the total and named in `missing`, so the screen can say the figure
 * is partial. Substituting a stale or neighbouring currency's rate would make the number look
 * complete while being wrong, which is worse than admitting the gap.
 *
 * ## The arithmetic is BCMath, not native integers
 *
 * A first version scaled each rate to a fixed-point integer and multiplied. It overflowed: an
 * amount in minor units times a rate scaled by 10^10 passes `PHP_INT_MAX` at surprisingly ordinary
 * balances, and PHP answers by silently converting to float — which `intdiv()` then rejects, so the
 * whole screen 500s. A float would have been worse than the crash: money must never round through
 * binary floating point.
 *
 * BCMath has no such ceiling. Amounts stay integer minor units end to end, the intermediate carries
 * more precision than any published rate, and the single rounding is half-up at the very end.
 */
final readonly class DisplayCurrencyConverter
{
    /** Intermediate precision, well beyond the four decimals the BNR publishes. */
    private const int SCALE = 12;

    public function __construct(private FxRateQuery $rates)
    {
    }

    /**
     * @param  array<string, int>  $amountsByCurrency  minor units, keyed by ISO 4217 code
     */
    public function convert(array $amountsByCurrency, string $displayCurrency, ?DateTimeImmutable $asOf = null): ConvertedTotal
    {
        $displayCurrency = strtoupper($displayCurrency);
        $asOf ??= new DateTimeImmutable('today');

        $displayRate = $this->perUnitRate($displayCurrency, $asOf);

        if ($displayRate === null) {
            // The display currency itself has no rate, so nothing can be expressed in it. Every
            // amount is reported as missing rather than a zero total being shown as if real.
            return new ConvertedTotal($displayCurrency, 0, [], array_keys($amountsByCurrency));
        }

        $totalMinor = 0;
        $used = [];
        $missing = [];

        foreach ($amountsByCurrency as $currency => $amountMinor) {
            $currency = strtoupper($currency);

            if ($currency === $displayCurrency) {
                $totalMinor += $amountMinor;

                continue;
            }

            $rate = $this->perUnitRate($currency, $asOf);

            if ($rate === null) {
                $missing[] = $currency;

                continue;
            }

            // amount x rate(source) / rate(display) — through RON, the currency both rates are
            // quoted against. One rounding, half-up, after both steps: rounding in the middle would
            // lose bani on every currency and the error would grow with the number of them.
            $inRon = bcmul((string) $amountMinor, $rate['decimal'], self::SCALE);
            $converted = bcdiv($inRon, $displayRate['decimal'], self::SCALE);

            $totalMinor += self::roundHalfUp($converted);

            $used[$currency] = [
                'rate' => $rate['decimal'],
                'rate_date' => $rate['date'],
                'source' => $rate['source'],
            ];
        }

        if ($displayCurrency !== CurrencyCode::BASE && $displayRate['date'] !== '') {
            // The display currency's own rate is part of the arithmetic, so it is part of the
            // evidence too.
            $used[$displayCurrency] = [
                'rate' => $displayRate['decimal'],
                'rate_date' => $displayRate['date'],
                'source' => $displayRate['source'],
            ];
        }

        return new ConvertedTotal($displayCurrency, $totalMinor, $used, $missing);
    }

    /**
     * RON per one unit of `$currency`, as a decimal string plus the evidence for it.
     *
     * RON is answered without a lookup: it is the currency the BNR quotes everything else against,
     * so its rate against itself is exactly 1 and there is no published row to find.
     *
     * @return array{decimal: numeric-string, date: string, source: string}|null
     */
    private function perUnitRate(string $currency, DateTimeImmutable $asOf): ?array
    {
        if ($currency === CurrencyCode::BASE) {
            return ['decimal' => '1', 'date' => '', 'source' => ''];
        }

        $found = $this->rates->latestRateOnOrBefore($currency, $asOf);

        if ($found === null) {
            return null;
        }

        $decimal = $found->perUnitRate->toString();

        // `DecimalRate` only ever renders a numeric string, but the type system cannot know that
        // and BCMath refuses anything else. Asserted rather than cast: a non-numeric rate here
        // would mean the value object's invariant had broken, and coercing it to zero would turn
        // that into a wrong total rather than a loud failure.
        assert(is_numeric($decimal));

        return [
            'decimal' => $decimal,
            'date' => $found->rateDate->format('Y-m-d'),
            'source' => $found->fxSourceCode,
        ];
    }

    /**
     * A BCMath decimal to whole minor units, rounded half-up.
     *
     * BCMath truncates, so half-up is applied by hand: add a half of the last place and then cut.
     * Done on the string rather than by casting to float, which is the one thing money arithmetic
     * must never touch.
     */
    private static function roundHalfUp(string $value): int
    {
        // Every caller passes BCMath output, which is numeric by construction; asserted so the
        // guarantee is visible rather than assumed, and loud if it ever stops being true.
        assert(is_numeric($value));

        $shifted = bcadd($value, str_starts_with($value, '-') ? '-0.5' : '0.5', self::SCALE);

        return (int) bcadd($shifted, '0', 0);
    }
}
