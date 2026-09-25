<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\ValueObjects;

use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;
use Kern\TaxRomania\TaxEngine\Enums\RoundingMode;
use Kern\TaxRomania\TaxEngine\Exceptions\CurrencyMismatchException;
use Kern\TaxRomania\TaxEngine\Exceptions\InvalidCurrencyCodeException;
use Kern\TaxRomania\TaxEngine\Exceptions\InvalidDecimalPlacesException;
use Stringable;

/**
 * Money, exclusively as integer minor units plus an explicit currency (`BLUEPRINT.md` §11.3,
 * `docs/AGENTS.md` rule 6 — **never a float**, not even transiently inside an operation). Every
 * arithmetic method that can produce a fraction takes a {@see RoundingMode} explicitly; there is
 * no implicit default anywhere in this class.
 *
 * `$decimalPlaces` defaults to 2 (RON, EUR, USD, and every currency this product currently
 * handles) but is not hardcoded to 2, because assuming every ISO 4217 currency has exactly two
 * minor-unit decimals would itself be a fabricated fact this class has no business asserting —
 * a caller for a currency with a different minor-unit scale supplies it explicitly.
 *
 * All fractional arithmetic goes through `ext-bcmath` on decimal-string representations — the
 * same tool `Kern\TaxRomania\Fx\ValueObjects\DecimalRate` already uses and is reused here for rate
 * values rather than re-implemented (`docs/handoff/014-source-registry-bnr.md` decision #4's
 * reasoning about `NUMERIC`/float round-tripping applies identically to money).
 */
final readonly class Money implements Stringable
{
    private const int WORKING_SCALE = 24;

    private function __construct(
        public int $minorUnits,
        public string $currencyCode,
        public int $decimalPlaces,
    ) {
    }

    public static function ofMinorUnits(int $minorUnits, string $currencyCode, int $decimalPlaces = 2): self
    {
        self::assertValidCurrencyCode($currencyCode);
        self::assertValidDecimalPlaces($decimalPlaces);

        return new self($minorUnits, strtoupper($currencyCode), $decimalPlaces);
    }

    public static function zero(string $currencyCode, int $decimalPlaces = 2): self
    {
        return self::ofMinorUnits(0, $currencyCode, $decimalPlaces);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currencyCode, $this->decimalPlaces);
    }

    public function add(self $other): self
    {
        $this->assertSameDenomination($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currencyCode, $this->decimalPlaces);
    }

    public function subtract(self $other): self
    {
        return $this->add($other->negated());
    }

    public function compareTo(self $other): int
    {
        $this->assertSameDenomination($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->currencyCode === $other->currencyCode
            && $this->decimalPlaces === $other->decimalPlaces
            && $this->minorUnits === $other->minorUnits;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * The larger of `$this`/`$other` — used by ceiling-style rules ("tax due, floored at zero").
     */
    public function max(self $other): self
    {
        return $this->isGreaterThan($other) ? $this : $other;
    }

    public function min(self $other): self
    {
        return $this->isLessThan($other) ? $this : $other;
    }

    /**
     * `$this * $rate`, e.g. a taxable base multiplied by a percentage rate expressed as a decimal
     * fraction (0.10 for 10%) — the core operation every percentage-of-base tax rule needs.
     * Never introduces the percentage itself; the caller supplies `$rate` (resolved from an
     * approved `fiscal_parameter_versions` row, never a literal in rule code —
     * `docs/AGENTS.md` rule 3).
     */
    public function multipliedByDecimal(DecimalRate $rate, RoundingMode $roundingMode): self
    {
        $rateString = $rate->toString();
        assert(is_numeric($rateString));

        $exact = bcmul((string) $this->minorUnits, $rateString, self::WORKING_SCALE);

        return new self(self::roundToInteger($exact, $roundingMode), $this->currencyCode, $this->decimalPlaces);
    }

    /**
     * Converts to `$targetCurrency` using an already-resolved per-unit rate (1 unit of this
     * money's currency = `$perUnitRate` units of `$targetCurrency`) — the rate itself, the method
     * that produced it, and its full provenance are `Kern\TaxRomania\TaxEngine\Services\
     * FxConversionPolicy`'s job, never this class's (`BLUEPRINT.md` §6.9 — the *rule* chooses the
     * method, this is pure arithmetic on an already-chosen rate).
     *
     * Correct regardless of whether the source and target currencies share the same
     * `$decimalPlaces` (RON/EUR both use 2; a future currency might not) — the minor-unit
     * exponent difference is applied exactly via `bcmul`/`bcdiv`, never a float shortcut.
     */
    public function convertedTo(string $targetCurrency, int $targetDecimalPlaces, DecimalRate $perUnitRate, RoundingMode $roundingMode): self
    {
        self::assertValidCurrencyCode($targetCurrency);
        self::assertValidDecimalPlaces($targetDecimalPlaces);

        $exponentDelta = $targetDecimalPlaces - $this->decimalPlaces;

        $perUnitRateString = $perUnitRate->toString();
        assert(is_numeric($perUnitRateString));

        $exact = bcmul((string) $this->minorUnits, $perUnitRateString, self::WORKING_SCALE);

        if ($exponentDelta > 0) {
            $exact = bcmul($exact, bcpow('10', (string) $exponentDelta), self::WORKING_SCALE);
        } elseif ($exponentDelta < 0) {
            $exact = bcdiv($exact, bcpow('10', (string) abs($exponentDelta)), self::WORKING_SCALE);
        }

        return new self(
            self::roundToInteger($exact, $roundingMode),
            strtoupper($targetCurrency),
            $targetDecimalPlaces,
        );
    }

    public function toString(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = str_pad((string) abs($this->minorUnits), $this->decimalPlaces + 1, '0', STR_PAD_LEFT);

        if ($this->decimalPlaces === 0) {
            return $sign . $absolute;
        }

        $wholePart = substr($absolute, 0, -$this->decimalPlaces);
        $fractionalPart = substr($absolute, -$this->decimalPlaces);

        return sprintf('%s%s.%s %s', $sign, $wholePart, $fractionalPart, $this->currencyCode);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private function assertSameDenomination(self $other): void
    {
        if ($this->currencyCode !== $other->currencyCode || $this->decimalPlaces !== $other->decimalPlaces) {
            throw CurrencyMismatchException::forOperation($this->currencyCode, $other->currencyCode);
        }
    }

    private static function assertValidCurrencyCode(string $currencyCode): void
    {
        if (! preg_match('/^[A-Za-z]{3}$/', $currencyCode)) {
            throw InvalidCurrencyCodeException::forValue($currencyCode);
        }
    }

    private static function assertValidDecimalPlaces(int $decimalPlaces): void
    {
        if ($decimalPlaces < 0) {
            throw InvalidDecimalPlacesException::forValue($decimalPlaces);
        }
    }

    /**
     * Rounds an exact BCMath decimal string to an integer minor-unit amount per `$mode`. Works on
     * the absolute value and reapplies the sign, so every mode is symmetric around zero (a
     * negative `Money` — a correction/reversal — rounds exactly as its positive counterpart
     * would).
     */
    private static function roundToInteger(string $exact, RoundingMode $mode): int
    {
        assert(is_numeric($exact));

        $isNegative = bccomp($exact, '0', self::WORKING_SCALE) < 0;
        $absolute = $isNegative ? bcmul($exact, '-1', self::WORKING_SCALE) : $exact;

        $truncated = bcadd($absolute, '0', 0);
        $fraction = bcsub($absolute, $truncated, self::WORKING_SCALE);

        $rounded = match ($mode) {
            RoundingMode::DOWN => $truncated,
            RoundingMode::UP => bccomp($fraction, '0', self::WORKING_SCALE) > 0
                ? bcadd($truncated, '1', 0)
                : $truncated,
            RoundingMode::HALF_UP => bccomp($fraction, '0.5', self::WORKING_SCALE) >= 0
                ? bcadd($truncated, '1', 0)
                : $truncated,
            RoundingMode::HALF_DOWN => bccomp($fraction, '0.5', self::WORKING_SCALE) > 0
                ? bcadd($truncated, '1', 0)
                : $truncated,
            RoundingMode::HALF_EVEN => self::roundHalfEven($truncated, $fraction),
        };

        $result = (int) $rounded;

        return $isNegative ? -$result : $result;
    }

    private static function roundHalfEven(string $truncated, string $fraction): string
    {
        assert(is_numeric($truncated));
        assert(is_numeric($fraction));

        $comparison = bccomp($fraction, '0.5', self::WORKING_SCALE);

        if ($comparison < 0) {
            return $truncated;
        }

        if ($comparison > 0) {
            return bcadd($truncated, '1', 0);
        }

        $truncatedIsEven = (int) bcmod($truncated, '2') === 0;

        return $truncatedIsEven ? $truncated : bcadd($truncated, '1', 0);
    }
}
