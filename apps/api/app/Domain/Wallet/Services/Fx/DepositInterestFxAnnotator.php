<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services\Fx;

use App\Domain\Fx\Exceptions\FxRateNotFoundException;
use App\Domain\Fx\Services\FxRateQuery;
use App\Domain\Fx\ValueObjects\DecimalRate;
use App\Domain\Wallet\DataTransferObjects\DepositInterestFxAnnotation;
use DateTimeImmutable;

/**
 * Produces the FX provenance chain (`BLUEPRINT.md` §7.5: amount, currency, rate, date, source,
 * method, result — all together) for one foreign-currency deposit interest period. Uses
 * `App\Domain\Fx\Services\FxRateQuery`, the sanctioned cross-module entry point
 * `docs/handoff/014-source-registry-bnr.md` documents — never queries `Domain/Fx`'s tables or
 * models directly.
 *
 * ## Which rate, and why this is a wallet bookkeeping choice, not a fiscal one
 *
 * `BLUEPRINT.md` §7.4 is explicit that a fiscal `TaxRule` must **itself** choose spot/monthly-
 * average/annual-average/etc., deliberately, per rule — no module may bake in a single FX method
 * as "the" fiscal conversion. `Domain/TaxEngine` did not exist yet when this module was built, so
 * there is no fiscal rule for this class to consult. This class therefore uses the BNR **spot
 * rate on the day the interest is recognised** (`accrualTo`, the period's own last day) purely so
 * this milestone's wallet record has *a* concrete RON-equivalent figure to display and store its
 * provenance for — see `self::METHOD_SPOT_ON_ACCRUAL_END`, recorded verbatim on the row precisely
 * so nothing downstream mistakes it for an authoritative fiscal conversion.
 *
 * **`Domain/TaxEngine`, once it exists, must not treat `deposit_interest_events.
 * ron_equivalent_minor` as the fiscal RON amount for a `TaxFact`.** It should independently
 * convert the event's own `gross_interest_minor` + `currency_code` + `accrual_to` (or whichever
 * date its own rule requires) via `FxRateQuery` itself, using whatever method that specific tax
 * rule decides is correct — see `docs/handoff/021-wallet.md` "Interfaces exposed to other
 * modules" for the exact contract.
 */
final readonly class DepositInterestFxAnnotator
{
    public const string METHOD_SPOT_ON_ACCRUAL_END = 'SPOT_DAY_OF_ACCRUAL_END';

    public function __construct(
        private FxRateQuery $fxRateQuery,
    ) {
    }

    /**
     * The RON provenance for a period, or **null when no rate has been published for its accrual
     * date yet**.
     *
     * A deposit's schedule covers its whole life, so most of its periods end in the future — and a
     * future date has no BNR rate, by definition. `FxRateQuery` is deliberately strict about that
     * (no interpolation, no nearest-day fallback: an exact miss throws, because a guessed rate on a
     * tax figure is worse than no figure), so asking it for a future date is a question with no
     * honest answer.
     *
     * Null is that answer, and it is recorded as null on the row rather than filled with today's
     * rate: the gross interest in the deposit's own currency is known now, the RON amount it will
     * convert to is not, and a provenance chain (`BLUEPRINT.md` §7.5) that pointed at the wrong
     * day's XML would be worse than an empty one. The row is rewritten with the real rate when the
     * schedule is regenerated after that date has passed — `App\Domain\Wallet\Actions\
     * GenerateDepositInterestSchedule` updates any period that is not yet `paid_at`.
     *
     * Same answer for a *past* date the BNR never published (a bank holiday, or a gap in the
     * imported history). That is a data gap to be filled by re-running the FX import, not a reason
     * to fail the whole schedule and leave the deposit with no interest figure at all.
     */
    public function tryAnnotate(string $currencyCode, DateTimeImmutable $accrualTo, int $grossInterestMinor): ?DepositInterestFxAnnotation
    {
        try {
            return $this->annotate($currencyCode, $accrualTo, $grossInterestMinor);
        } catch (FxRateNotFoundException) {
            return null;
        }
    }

    /**
     * Throws `FxRateNotFoundException` when no rate exists for `$accrualTo`. Callers computing a
     * schedule that reaches into the future want `self::tryAnnotate()` instead.
     */
    public function annotate(string $currencyCode, DateTimeImmutable $accrualTo, int $grossInterestMinor): DepositInterestFxAnnotation
    {
        $withProvenance = $this->fxRateQuery->rateWithProvenance($currencyCode, $accrualTo);

        return new DepositInterestFxAnnotation(
            perUnitRateDecimal: $withProvenance->perUnitRate->toString(),
            rateDate: $withProvenance->rateDate,
            sourceCode: $withProvenance->fxSourceCode,
            method: self::METHOD_SPOT_ON_ACCRUAL_END,
            contentSha256: $withProvenance->contentSha256,
            sourceDocumentVersionId: $withProvenance->sourceDocumentVersionId,
            ronEquivalentMinor: $this->convert($grossInterestMinor, $withProvenance->perUnitRate),
        );
    }

    /**
     * `$minorUnits * perUnitRate`, rounded half-up to the nearest whole RON minor unit (ban).
     * Both source and target currencies use a 2-decimal minor unit, so — unlike a display
     * conversion between major-unit amounts — no factor-of-100 rescaling is needed: converting
     * minor-unit amounts directly by the same per-major-unit rate is exact
     * (`sourceMinor / 100 * rate = ronMajor` implies `sourceMinor * rate = ronMajor * 100 =
     * ronMinor`).
     */
    private function convert(int $minorUnits, DecimalRate $perUnitRate): int
    {
        $rate = $perUnitRate->toString();
        assert(is_numeric($rate));

        $product = bcmul((string) $minorUnits, $rate, 6);

        return (int) bcadd($product, '0.5', 0);
    }
}
