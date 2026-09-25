<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\ValueObjects;

use Kern\TaxRomania\Wallet\ValueObjects\Exceptions\InvalidDecimalException;
use Stringable;

/**
 * A non-negative, arbitrary-precision decimal — an annual interest rate, a day-count year
 * fraction, or an intermediate interest sum — represented as a validated numeric string and
 * operated on exclusively through BCMath. Never a float, for the same reason
 * `Kern\TaxRomania\Fx\ValueObjects\DecimalRate` gives: this feeds a fiscally-relevant amount
 * (a deposit's interest), so no floating-point rounding may enter the calculation
 * (`docs/AGENTS.md` rule 6).
 *
 * **Deliberately a second, independent type from `Kern\TaxRomania\Fx\ValueObjects\DecimalRate`**,
 * despite the near-identical shape — see `docs/handoff/021-wallet.md` "Key decisions". In short:
 * this type's values (an `annual_rate_decimal` from a deposit contract, a day-count year
 * fraction) have nothing to do with FX conversion, and reaching into `Domain/Fx`'s value object
 * for unrelated interest math would couple this module's core arithmetic to another module's
 * FX-specific type for no reason beyond avoiding a ~90-line class. `Domain/Fx`'s own
 * `DecimalRate` **is** still used directly, unmodified, for the one place a value genuinely
 * originates from Fx: `Kern\TaxRomania\Wallet\Services\Fx\DepositInterestFxAnnotator` consumes
 * `FxRateQuery::rateWithProvenance()`'s `DecimalRate` return value as-is.
 *
 * Only non-negative values are supported — no quantity this module's interest engine handles is
 * ever negative by construction, so signed-value handling would be untested, dead code
 * (`docs/CODE-QUALITY.md` §2).
 *
 * @phpstan-type NumericString numeric-string
 */
final readonly class Decimal implements Stringable
{
    /**
     * @param numeric-string $value
     */
    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw InvalidDecimalException::forValue($value);
        }

        assert(is_numeric($value));

        return new self($value);
    }

    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw InvalidDecimalException::forValue((string) $value);
        }

        return new self((string) $value);
    }

    public static function zero(): self
    {
        return self::fromString('0');
    }

    public function add(self $other, int $scale): self
    {
        return new self(bcadd($this->value, $other->value, $scale));
    }

    public function multiply(self $other, int $scale): self
    {
        return new self(bcmul($this->value, $other->value, $scale));
    }

    public function divide(self $divisor, int $scale): self
    {
        if ($divisor->isZero()) {
            throw InvalidDecimalException::forNonPositiveDivisor();
        }

        return new self(bcdiv($this->value, $divisor->value, $scale));
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', 16) === 0;
    }

    /**
     * Round-half-up to `$scale` decimal places. BCMath's own operations truncate; adding half a
     * unit at the target precision and letting `bcadd()`'s `$scale` argument truncate the result
     * is exactly round-half-up for a non-negative value — the same idiom
     * `Kern\TaxRomania\Fx\ValueObjects\DecimalRate::roundedTo()` documents and uses.
     */
    public function roundedTo(int $scale): self
    {
        return new self(bcadd($this->value, self::halfUnitInLastPlace($scale), $scale));
    }

    /**
     * The integer part, after rounding half-up to zero decimal places. The one place this class
     * crosses back into plain PHP `int` — used to turn an interest sum (a `Decimal` counted in
     * minor units) into the `int` a `Money`/database column actually stores.
     */
    public function toRoundedInt(): int
    {
        return (int) $this->roundedTo(0)->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @return numeric-string
     */
    private static function halfUnitInLastPlace(int $scale): string
    {
        $value = '0.' . str_repeat('0', $scale) . '5';

        assert(is_numeric($value));

        return $value;
    }
}
