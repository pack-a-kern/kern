<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\DataTransferObjects\InstrumentTerms;
use Kern\Investments\DataTransferObjects\PayoutTerms;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\PayoutKind;
use Kern\Platform\Money\CurrencyCode;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\TaxEngine\Contracts\FiscalParameterProvider;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousFiscalParameterVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoFiscalParameterVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownFiscalParameterException;
use Kern\TaxRomania\Wallet\DataTransferObjects\PostedHoldingPayouts;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Enums\SkippedPayout;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Models\InvestmentLotPayout;
use Kern\TaxRomania\Wallet\Services\DayCount\DayCountCalculator;
use Kern\TaxRomania\Wallet\Services\DepositMovements;
use Kern\TaxRomania\Wallet\Services\Fx\DepositInterestFxAnnotator;
use Kern\TaxRomania\Wallet\Services\Holdings\LotAmounts;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Credits every lot what its instrument has paid it — coupons, dividends, the repayment at maturity — as
 * a deposit's interest is posted once its period has closed (`PostMaturedDepositInterest`): a payout whose
 * payment date has come is money that has arrived, and becomes a transaction in the lot's account.
 *
 * ## Who is owed a payout
 *
 * The units held the day before the payout's date and not sold before it (ADR 0025 §9): bought on that
 * date or later, or sold before it, they have no right to it. The date is the ex-dividend date for a
 * dividend; the record date for a coupon — or the period's last day, for Tezaur, which has none; the
 * payment date for the repayment.
 *
 * ## How much
 *
 * Units × the amount per unit the source gives. A Tezaur coupon has only its rate: it is computed as its
 * prospectus says (art. 13), face value × rate × the days of the period over the days of the current year
 * — `ACTUAL_ACTUAL` — and rounded once, on the investor's total. A corporate or a municipal bond is owed
 * no coupon here: BVB publishes its rate, not its payment dates, and a coupon is never guessed.
 *
 * A dividend is credited **net** of the tax the company withholds, and the withheld part is recorded
 * beside it. The rate is never written here: it is `RO.INCOME_TAX.DIVIDEND_RATE`, read from the Fiscal
 * Code and counted only once a person approved it. Without an approved value on the payment date the
 * dividend is not credited — the run reports it, and the next run, once one is approved, credits it.
 *
 * A holding in euro carries the BNR conversion of its gross, as a deposit's interest does.
 *
 * Across every taxpayer, like every nightly task: no `TenantContext` exists. Idempotent — one row per lot
 * and payout, so a payout already credited is never credited twice.
 */
final readonly class PostHoldingPayouts
{
    public const string DIVIDEND_RATE = 'RO.INCOME_TAX.DIVIDEND_RATE';

    public function __construct(
        private MarketData $market,
        private FiscalParameterProvider $parameters,
        private DayCountCalculator $dayCount,
        private DepositInterestFxAnnotator $fx,
        private DepositMovements $movements,
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(?CarbonImmutable $asOf = null): PostedHoldingPayouts
    {
        $today = ($asOf ?? CarbonImmutable::now('UTC'))->startOfDay()->toDateTimeImmutable();
        $posted = 0;
        $skipped = [];

        /** @var list<string> $instrumentIds */
        $instrumentIds = InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->distinct()->pluck('instrument_id')->all();

        foreach ($this->market->instruments($instrumentIds) as $instrument) {
            /** @var list<InvestmentLot> $lots */
            $lots = InvestmentLot::query()->withoutGlobalScope(TenantScope::class)
                ->where('instrument_id', $instrument->id)
                ->orderBy('bought_on')
                ->get()
                ->all();

            if ($lots === []) {
                continue;
            }

            $credited = array_flip(InvestmentLotPayout::query()->withoutGlobalScope(TenantScope::class)
                ->whereIn('lot_id', array_map(static fn (InvestmentLot $lot): string => $lot->id, $lots))
                ->get(['lot_id', 'payout_id'])
                ->map(static fn (InvestmentLotPayout $row): string => "{$row->lot_id}|{$row->payout_id}")
                ->all());

            foreach ($this->market->payouts([$instrument->id], $lots[0]->bought_on->toDateTimeImmutable(), $today) as $payout) {
                if ($payout->kind === PayoutKind::COUPON && $instrument->kind !== InstrumentKind::GOVERNMENT_BOND) {
                    continue;
                }

                $owedOn = match ($payout->kind) {
                    PayoutKind::DIVIDEND => $payout->exDate,
                    PayoutKind::COUPON => $payout->recordDate ?? $payout->periodEnd ?? $payout->paymentDate,
                    PayoutKind::PRINCIPAL => $payout->paymentDate,
                };
                // Without its date, a dividend is reported for the lots held when it was paid — the ones it may be owed to.
                $on = $owedOn ?? $payout->paymentDate;
                $owed = array_filter($lots, static fn (InvestmentLot $lot): bool => ! isset($credited["{$lot->id}|{$payout->id}"])
                    && $lot->bought_on->toDateTimeImmutable() < $on
                    && ($lot->sold_on === null || $lot->sold_on->toDateTimeImmutable() >= $on));

                if ($owed === []) {
                    continue;
                }

                $perUnit = $this->perUnit($payout, $instrument);
                $rate = $payout->kind === PayoutKind::DIVIDEND ? $this->dividendRate($payout->paymentDate) : null;
                $skip = match (true) {
                    $owedOn === null => SkippedPayout::NO_EX_DATE,
                    $perUnit === null => SkippedPayout::NO_AMOUNT,
                    $payout->kind === PayoutKind::DIVIDEND && $rate === null => SkippedPayout::NO_APPROVED_DIVIDEND_RATE,
                    default => null,
                };

                if ($skip !== null) {
                    $skipped[$skip->value] = ($skipped[$skip->value] ?? 0) + 1;

                    continue;
                }

                assert($perUnit !== null);

                foreach ($owed as $lot) {
                    $this->credit($lot, $instrument, $payout, $perUnit, $rate);
                    $posted++;
                }
            }
        }

        return new PostedHoldingPayouts($posted, $skipped);
    }

    /** @param numeric-string $perUnit */
    private function credit(InvestmentLot $lot, InstrumentTerms $instrument, PayoutTerms $payout, string $perUnit, ?ResolvedFiscalParameter $rate): void
    {
        $gross = LotAmounts::minor($lot->units, $perUnit);
        $withheld = null;

        if ($rate !== null) {
            $fraction = $rate->asDecimalRate()->toString();
            assert(is_numeric($fraction));
            $withheld = LotAmounts::rounded(bcmul((string) $gross, $fraction, 15));
        }

        $net = $gross - ($withheld ?? 0);
        $fx = $lot->currency === CurrencyCode::BASE ? null : $this->fx->tryAnnotate($lot->currency, $payout->paymentDate, $gross);

        $row = DB::transaction(function () use ($lot, $instrument, $payout, $gross, $withheld, $net, $rate, $fx): InvestmentLotPayout {
            $transaction = $net === 0 ? null : $this->movements->write(
                subjectId: $lot->tax_subject_id,
                accountId: $lot->financial_account_id,
                // The day it was paid, not the night this ran: a late run must not move income into another year.
                bookedAt: $payout->paymentDate->format('Y-m-d'),
                amountMinor: $net,
                currencyCode: $lot->currency,
                description: $instrument->name,
                // The face value repaid is the person's own money coming back; a coupon or a dividend is income.
                category: $payout->kind === PayoutKind::PRINCIPAL ? DepositMovements::TRANSFER : DepositMovements::INTEREST,
            );

            return InvestmentLotPayout::query()->withoutGlobalScope(TenantScope::class)->create([
                'tax_subject_id' => $lot->tax_subject_id,
                'lot_id' => $lot->id,
                'payout_id' => $payout->id,
                'kind' => $payout->kind,
                'paid_on' => $payout->paymentDate->format('Y-m-d'),
                'currency' => $lot->currency,
                'gross_minor' => $gross,
                'withheld_minor' => $withheld,
                'net_minor' => $net,
                'withholding_rate_version_id' => $rate?->parameterVersionId,
                'transaction_id' => $transaction === null ? null : DepositMovements::id($transaction),
                'fx_rate_decimal' => $fx?->perUnitRateDecimal,
                'fx_rate_date' => $fx?->rateDate->format('Y-m-d'),
                'fx_source_code' => $fx?->sourceCode,
                'fx_method' => $fx?->method,
                'fx_content_sha256' => $fx?->contentSha256,
                'fx_source_document_version_id' => $fx?->sourceDocumentVersionId,
                'ron_equivalent_minor' => $fx?->ronEquivalentMinor,
            ]);
        });

        $this->audit->record(
            subjectId: $lot->tax_subject_id,
            actorId: null,
            eventType: WalletAuditEvent::HOLDING_PAYOUT_POSTED->value,
            resourceType: WalletAuditEvent::HOLDING_PAYOUT_POSTED->resourceType(),
            resourceId: $row->transaction_id ?? $row->id,
            payload: [
                'lot_payout_id' => $row->id,
                'kind' => $payout->kind->value,
                'gross_minor' => $gross,
                'withheld_minor' => $withheld,
                'net_minor' => $net,
                'currency' => $lot->currency,
            ],
        );
    }

    /**
     * What one unit is paid: the source's amount, or — for a Tezaur coupon, which states only its rate —
     * face value × rate × ACT/ACT over the period, whose last day the period includes.
     *
     * @return numeric-string|null
     */
    private function perUnit(PayoutTerms $payout, InstrumentTerms $instrument): ?string
    {
        if ($payout->amountPerUnit !== null) {
            assert(is_numeric($payout->amountPerUnit));

            return $payout->amountPerUnit;
        }

        $rate = $payout->ratePercent ?? $instrument->ratePercent;

        if ($payout->kind !== PayoutKind::COUPON || $payout->periodStart === null || $payout->periodEnd === null || $rate === null || $instrument->faceValue === null) {
            return null;
        }

        $fraction = $this->dayCount->yearFraction(DayCountConvention::ACTUAL_ACTUAL, $payout->periodStart, $payout->periodEnd->modify('+1 day'))->toString();
        assert(is_numeric($fraction) && is_numeric($rate) && is_numeric($instrument->faceValue));

        return bcdiv(bcmul(bcmul($instrument->faceValue, $rate, 15), $fraction, 15), '100', 15);
    }

    /** The approved dividend rate on the day it is paid, or null when there is none to count. */
    private function dividendRate(DateTimeImmutable $on): ?ResolvedFiscalParameter
    {
        try {
            $rate = $this->parameters->versionInForceOn(self::DIVIDEND_RATE, $on);
        } catch (UnknownFiscalParameterException | NoFiscalParameterVersionInForceException | AmbiguousFiscalParameterVersionException) {
            return null;
        }

        return $rate->status === RuleApprovalStatus::APPROVED ? $rate : null;
    }
}
