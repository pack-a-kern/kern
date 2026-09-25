<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\ValueObjects;

use Kern\TaxRomania\Wallet\ValueObjects\Exceptions\CurrencyMismatchException;
use Kern\TaxRomania\Wallet\ValueObjects\Exceptions\InvalidMoneyException;
use Stringable;

/**
 * An exact monetary amount: integer minor units (e.g. bani, cents) plus an ISO 4217 alphabetic
 * currency code. `BLUEPRINT.md` §6.3/§19.1/`docs/AGENTS.md` rule 6: **never a float**, here or
 * anywhere this value object is used — every column this module stores money in is a signed
 * integer, and every arithmetic operation on `Money` is plain integer arithmetic (add/subtract),
 * never division, which is exactly why integer minor units are sufficient here: unlike an
 * exchange rate (`Kern\TaxRomania\Fx\ValueObjects\DecimalRate`), an amount of money is never itself
 * divided by a fractional factor inside this module — `Kern\TaxRomania\Wallet\Services\Fx\
 * DepositInterestFxAnnotator` is the one place a `Money` amount is multiplied by a `DecimalRate`,
 * and it does so via BCMath on the minor-unit integer directly (see that class), not through this
 * value object.
 *
 * **Why this is a second `Money`-shaped type, not a shared one with `Domain/TaxEngine`** — see
 * `docs/handoff/021-wallet.md` "Key decisions" for the full reasoning; in short: `docs/AGENTS.md`
 * §6 forbids a module importing another module's types outside the DTO/contract/event shapes, a
 * plain immutable integer+currency value object is infrastructure-shaped rather than fiscal
 * domain knowledge (`docs/CODE-QUALITY.md` §1's "incidental similarity" carve-out), and
 * `Domain/TaxEngine` was, at the time this module was built, still unbuilt scaffolding owned by a
 * concurrently-working agent — depending on it would have coupled this module's tests to another
 * agent's in-flight work.
 *
 * Only alphabetic-shape validation is performed on the currency code (`^[A-Z]{3}$`), the same
 * level of validation `Kern\TaxRomania\Fx\Parsing\BnrXmlParser` applies to currency codes — this
 * module has no ISO 4217 registry to validate membership against, and inventing one would be
 * speculative scope this task was not asked to build.
 */
final readonly class Money implements Stringable
{
    private function __construct(
        private int $minorUnits,
        private string $currencyCode,
    ) {
    }

    public static function fromMinorUnits(int $minorUnits, string $currencyCode): self
    {
        if (! preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw InvalidMoneyException::forCurrencyCode($currencyCode);
        }

        return new self($minorUnits, $currencyCode);
    }

    public static function zero(string $currencyCode): self
    {
        return self::fromMinorUnits(0, $currencyCode);
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currencyCode(): string
    {
        return $this->currencyCode;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currencyCode);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currencyCode);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currencyCode);
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currencyCode === $other->currencyCode;
    }

    public function __toString(): string
    {
        return sprintf('%s %d', $this->currencyCode, $this->minorUnits);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currencyCode !== $other->currencyCode) {
            throw CurrencyMismatchException::forOperation($this->currencyCode, $other->currencyCode);
        }
    }
}
