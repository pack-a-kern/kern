<?php

declare(strict_types=1);

namespace App\Domain\Rental\Services;

/**
 * Computes the owning tax subject's **share** of a rent amount, per `BLUEPRINT.md` §33's
 * co-ownership design constraint: only `ownership_percent_decimal` of the contractual rent is
 * this subject's income. Pure BCMath arithmetic — never a float (`docs/AGENTS.md` rule 6).
 *
 * Rounding is round-half-up to the nearest minor unit, using the exact idiom
 * `App\Domain\Wallet\ValueObjects\Decimal::roundedTo()` documents and uses (adding a half unit at
 * the target precision and letting `bcadd()`'s truncating `$scale` argument do the rounding) —
 * not a new value object, since this class needs exactly one operation and a full `Decimal`/
 * `Money` type would be `docs/CODE-QUALITY.md` §2's "abstraction with a single, non-generic use
 * that shouldn't exist" (the same reasoning `docs/handoff/021-wallet.md` gives for why `Domain/
 * Wallet` and `Domain/TaxEngine` each keep their own tiny money primitive rather than sharing one
 * — a plain BCMath calculation is infrastructure-shaped, not stable fiscal domain knowledge this
 * module would otherwise duplicate).
 *
 * `docs/AGENTS.md` rule 3: this class contains no tax rate, threshold, or fiscal percentage — the
 * ownership share is a plain property-ownership fact the subject themselves records, not a
 * sourced fiscal parameter.
 */
final class OwnershipShareCalculator
{
    private const int SCALE = 10;

    /**
     * @param string $ownershipPercentDecimal a validated decimal string in `(0, 100]`,
     *                                          e.g. `"50.00"` — validated eagerly by
     *                                          `App\Domain\Rental\Actions\RegisterProperty`, not
     *                                          re-validated here; only asserted, to narrow the
     *                                          type for BCMath.
     */
    public function shareOf(int $totalMinor, string $ownershipPercentDecimal): int
    {
        assert(is_numeric($ownershipPercentDecimal));

        $product = bcmul((string) $totalMinor, $ownershipPercentDecimal, self::SCALE);
        $ratio = bcdiv($product, '100', self::SCALE);
        $rounded = bcadd($ratio, '0.5', 0);

        return (int) $rounded;
    }
}
