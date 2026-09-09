<?php

declare(strict_types=1);

namespace App\Domain\Fx\ValueObjects;

use App\Domain\Fx\Exceptions\InvalidDecimalRateException;
use InvalidArgumentException;
use Stringable;

/**
 * A non-negative decimal amount — an exchange rate or an intermediate sum/average of rates —
 * represented as a validated numeric string and operated on exclusively through BCMath
 * (`docs/AGENTS.md` rule 6 / `docs/CODE-QUALITY.md` §4: no float for money, applied here to
 * exchange rates for the same reason: an FX rate feeds directly into a fiscal RON conversion).
 *
 * Only non-negative values are supported — every quantity this module handles (a published rate,
 * a multiplier-divided per-unit rate, an arithmetic mean of either) is non-negative by
 * construction, so signed-value handling would be untested, dead code
 * (`docs/CODE-QUALITY.md` §2).
 *
 * Internally typed as PHPStan's `numeric-string` (BCMath's own stubs require it) — `fromString()`
 * is the sole place a plain `string` is validated and narrowed; every other method only ever
 * passes already-`numeric-string` values (this class's own property, or BCMath's own
 * `numeric-string`-typed return values) to a BCMath function.
 */
final readonly class DecimalRate implements Stringable
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
            throw InvalidDecimalRateException::forValue($value);
        }

        assert(is_numeric($value));

        return new self($value);
    }

    public static function zero(): self
    {
        return self::fromString('0');
    }

    public function add(self $other, int $scale): self
    {
        $sum = bcadd($this->value, $other->value, $scale);

        return new self($sum);
    }

    public function divideByInt(int $divisor, int $scale): self
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException('Divisor must be a positive integer.');
        }

        $quotient = bcdiv($this->value, (string) $divisor, $scale);

        return new self($quotient);
    }

    /**
     * Absolute difference against `$other`, at `$scale` decimal places.
     */
    public function absoluteDifference(self $other, int $scale): self
    {
        $comparison = bccomp($this->value, $other->value, $scale);

        $difference = $comparison >= 0
            ? bcsub($this->value, $other->value, $scale)
            : bcsub($other->value, $this->value, $scale);

        return new self($difference);
    }

    public function isLessThanOrEqualTo(self $other, int $scale): bool
    {
        return bccomp($this->value, $other->value, $scale) <= 0;
    }

    public function equals(self $other): bool
    {
        $scale = max(self::fractionDigits($this->value), self::fractionDigits($other->value));

        return bccomp($this->value, $other->value, $scale) === 0;
    }

    /**
     * Round-half-up to `$scale` decimal places. BCMath's own operations truncate rather than
     * round, so this uses the standard "add half a unit at the target precision, then truncate"
     * idiom: adding `0.5 * 10^-scale` and letting `bcadd()`'s own `$scale` argument truncate the
     * result to that many places is exactly round-half-up for a non-negative value.
     */
    public function roundedTo(int $scale): self
    {
        $halfUlp = self::halfUnitInLastPlace($scale);
        $rounded = bcadd($this->value, $halfUlp, $scale);

        return new self($rounded);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function fractionDigits(string $value): int
    {
        $position = strpos($value, '.');

        return $position === false ? 0 : strlen($value) - $position - 1;
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
