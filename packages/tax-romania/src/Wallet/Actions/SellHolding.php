<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\Enums\Programme;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use Kern\TaxRomania\Wallet\DataTransferObjects\SellHoldingInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\HoldingRefusal;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Exceptions\HoldingRefusedException;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Models\InvestmentLotPayout;
use Kern\TaxRomania\Wallet\Services\DepositMovements;
use Kern\TaxRomania\Wallet\Services\Holdings\ApprovedParameter;
use Kern\TaxRomania\Wallet\Services\Holdings\HoldingIncome;
use Kern\TaxRomania\Wallet\Services\Holdings\LotAmounts;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Sells units of one instrument held in one account before maturity: the date, the units, the price per
 * unit and the fees (ADR 0025 §9). A Tezaur bond is refused — it is not traded, and ends at maturity.
 *
 * **First in, first out.** The units come from the account's open lots of the instrument, oldest
 * purchase first — the order the holding period of a share's gain is counted in (art. 97 (8^1) of the
 * Fiscal Code). A lot sold whole is closed; one sold in part is split, the units sold closing in a row of
 * their own with their share of the purchase fees, so each row stays one fact. What the lot was already
 * credited — a coupon, a dividend — is split with its units, so the closed row and the open one together
 * still hold exactly what was paid, and neither is owed it again.
 *
 * **What is written.** The sale fees are shared among the lots sold by units. The units cost what the
 * law says they cost — the weighted average price of the account's units of that instrument, purchase
 * fees included (art. 97 (8^2)) — and the gain is the proceeds, less the sale fees, less that cost. On a
 * share or a company bond the intermediary withholds the tax on it at the source (art. 97 (8^1), (8^4)):
 * each lot's part of the gain at the long rate when held at least the holding period, counted first in,
 * first out, at the short rate otherwise — the rates and the period the approved fiscal parameters, never
 * a number here; without them a gain is refused rather than guessed. Public debt is withheld nothing
 * (art. 93 (1) a)). A loss is no tax and is not carried (art. 97 (8^5)).
 *
 * The money arrives as one movement on the day of the sale: the cost coming back (`TRANSFER`) and the
 * gain, net of what was withheld, filed under its income (`HoldingIncome`).
 */
final readonly class SellHolding
{
    public function __construct(
        private MarketData $market,
        private DepositMovements $movements,
        private WalletAudit $audit,
        private ApprovedParameter $approved,
    ) {
    }

    public const string LONG_RATE = 'RO.INVESTMENTS.SECURITIES_GAIN_RATE_LONG';

    public const string SHORT_RATE = 'RO.INVESTMENTS.SECURITIES_GAIN_RATE_SHORT';

    public const string HOLDING_DAYS = 'RO.INVESTMENTS.SECURITIES_HOLDING_DAYS';

    /** @return list<InvestmentLot> the rows closed by the sale, oldest purchase first */
    public function __invoke(SellHoldingInput $input): array
    {
        $account = FinancialAccount::query()->findOrFail($input->accountId);

        if ($account->kind !== AccountKind::INVESTMENTS) {
            throw HoldingRefusedException::because(HoldingRefusal::NOT_AN_INVESTMENTS_ACCOUNT);
        }

        $instrument = $this->market->instruments([$input->instrumentId])[$input->instrumentId] ?? null;

        if ($instrument?->programme === Programme::TEZAUR) {
            throw HoldingRefusedException::because(HoldingRefusal::TEZAUR_ENDS_AT_MATURITY);
        }

        $sold = DB::transaction(function () use ($input, $account, $instrument): array {
            /** @var list<InvestmentLot> $open */
            $open = InvestmentLot::query()
                ->where('financial_account_id', $account->id)
                ->where('instrument_id', $input->instrumentId)
                ->whereNull('sold_on')
                ->whereDate('bought_on', '<=', $input->soldOn)
                ->orderBy('bought_on')
                // Entered in that order: a UUIDv7 grows with the time it was made.
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            $held = array_reduce($open, static fn (string $sum, InvestmentLot $lot): string => bcadd($sum, $lot->units, 6), '0');
            // What every unit held cost, fees included, before any is sold: the weighted average price.
            $heldCost = array_sum(array_map(static fn (InvestmentLot $lot): int => LotAmounts::minor($lot->units, $lot->price_per_unit) + $lot->fees_minor, $open));

            if ($instrument === null || bccomp($held, $input->units, 6) < 0) {
                throw HoldingRefusedException::because(HoldingRefusal::NOT_ENOUGH_UNITS);
            }

            if (! LotAmounts::withinCeiling($input->units, $input->pricePerUnit) || $input->feesMinor > LotAmounts::ceiling()) {
                throw HoldingRefusedException::because(HoldingRefusal::AMOUNT_TOO_LARGE);
            }

            $gross = LotAmounts::minor($input->units, $input->pricePerUnit);

            if ($input->feesMinor > $gross) {
                throw HoldingRefusedException::because(HoldingRefusal::FEES_EXCEED_PROCEEDS);
            }

            $left = $input->units;
            $feesLeft = $input->feesMinor;
            $closed = [];

            foreach ($open as $lot) {
                if (bccomp($left, '0', 6) <= 0) {
                    break;
                }

                // Units credited a payment after the day they are said to be sold were held then: the date is wrong.
                if (InvestmentLotPayout::query()->where('lot_id', $lot->id)->whereDate('paid_on', '>', $input->soldOn)->exists()) {
                    throw HoldingRefusedException::because(HoldingRefusal::SOLD_BEFORE_A_CREDITED_PAYMENT);
                }

                $take = bccomp($lot->units, $left, 6) <= 0 ? $lot->units : self::plain($left);
                $left = bcsub($left, $take, 6);
                // The last lot takes what is left of the fees, so their parts add up to them exactly.
                $fees = bccomp($left, '0', 6) === 0 ? $feesLeft : LotAmounts::share($input->feesMinor, $take, $input->units);
                $feesLeft -= $fees;
                $closed[] = $this->close($lot, $take, $input, $fees);
            }

            $proceeds = $gross - $input->feesMinor;
            $cost = LotAmounts::share($heldCost, $input->units, $held);
            $gain = $proceeds - $cost;
            $withheld = $gain > 0 && ! HoldingIncome::isPublicDebt($instrument->kind) ? $this->withhold($closed, $gain, $input) : 0;

            if ($proceeds - $withheld !== 0) {
                $this->movements->writeSplit(
                    subjectId: $account->tax_subject_id,
                    accountId: $account->id,
                    bookedAt: $input->soldOn,
                    currencyCode: $instrument->currency,
                    description: $instrument->name,
                    // A loss is the proceeds alone coming back; a gain, the cost back and the gain beside it.
                    splits: $gain > 0
                        ? [DepositMovements::TRANSFER => $cost, HoldingIncome::ofGain($instrument->kind) => $gain - $withheld]
                        : [DepositMovements::TRANSFER => $proceeds],
                );
            }

            return $closed;
        });

        foreach ($sold as $lot) {
            $this->audit->record(
                subjectId: $lot->tax_subject_id,
                actorId: $input->actorId,
                eventType: WalletAuditEvent::HOLDING_SOLD->value,
                resourceType: WalletAuditEvent::HOLDING_SOLD->resourceType(),
                resourceId: $lot->id,
                payload: [
                    'account_id' => $lot->financial_account_id,
                    'instrument_id' => $lot->instrument_id,
                    'units' => $lot->units,
                    'sold_on' => $input->soldOn,
                    'sold_price_per_unit' => $input->pricePerUnit,
                    'sale_fees_minor' => $lot->sale_fees_minor,
                ],
            );
        }

        return $sold;
    }

    /**
     * What the intermediary withheld on a gain, lot by lot, each lot's part of it by units at the rate its
     * own holding period gives — written on the lot — and the sum of it.
     *
     * @param list<InvestmentLot> $closed oldest purchase first
     */
    private function withhold(array $closed, int $gain, SellHoldingInput $input): int
    {
        $on = new DateTimeImmutable($input->soldOn);
        [$long, $short, $days] = array_map(
            fn (string $code): ResolvedFiscalParameter => $this->approved->onDay($code, $on)
                ?? throw HoldingRefusedException::because(HoldingRefusal::NO_APPROVED_SECURITIES_RATE),
            [self::LONG_RATE, self::SHORT_RATE, self::HOLDING_DAYS],
        );
        $period = $days->asJson()['days'] ?? null;

        if (! is_int($period)) {
            throw HoldingRefusedException::because(HoldingRefusal::NO_APPROVED_SECURITIES_RATE);
        }

        $total = 0;
        $left = $gain;

        foreach ($closed as $at => $lot) {
            // The last lot takes what is left of the gain, so the parts add up to it exactly.
            $part = $at === array_key_last($closed) ? $left : LotAmounts::share($gain, $lot->units, $input->units);
            $left -= $part;
            $rate = $lot->bought_on->diffInDays($on) >= $period ? $long : $short;
            $fraction = $rate->asDecimalRate()->toString();
            assert(is_numeric($fraction));
            $tax = LotAmounts::rounded(bcmul((string) $part, $fraction, 15));

            $lot->fill(['sale_tax_withheld_minor' => $tax])->save();
            $total += $tax;
        }

        return $total;
    }

    /** @param numeric-string $units */
    private function close(InvestmentLot $lot, string $units, SellHoldingInput $input, int $fees): InvestmentLot
    {
        $sale = ['sold_on' => $input->soldOn, 'sold_price_per_unit' => $input->pricePerUnit, 'sale_fees_minor' => $fees];

        if (bccomp($units, $lot->units, 6) === 0) {
            $lot->fill($sale)->save();

            return $lot;
        }

        $whole = $lot->units;
        $purchaseFees = LotAmounts::share($lot->fees_minor, $units, $whole);

        // The broker's reference stays with the open part: it names the position, which is still held.
        $closed = InvestmentLot::query()->create([
            'tax_subject_id' => $lot->tax_subject_id,
            'financial_account_id' => $lot->financial_account_id,
            'instrument_id' => $lot->instrument_id,
            'units' => $units,
            'price_per_unit' => $lot->price_per_unit,
            'bought_on' => $lot->bought_on->toDateString(),
            'fees_minor' => $purchaseFees,
            'currency' => $lot->currency,
            'held_at' => $lot->held_at,
            ...$sale,
        ]);

        $lot->fill(['units' => self::plain(bcsub($whole, $units, 6)), 'fees_minor' => $lot->fees_minor - $purchaseFees])->save();

        foreach (InvestmentLotPayout::query()->where('lot_id', $lot->id)->get() as $credited) {
            $part = static fn (?int $minor): ?int => $minor === null ? null : LotAmounts::share($minor, $units, $whole);
            $gross = (int) $part($credited->gross_minor);
            $withheld = $part($credited->withheld_minor);
            $ron = $part($credited->ron_equivalent_minor);

            InvestmentLotPayout::query()->create([
                ...$credited->only($credited->getFillable()),
                'lot_id' => $closed->id,
                'gross_minor' => $gross,
                'withheld_minor' => $withheld,
                'net_minor' => $gross - ($withheld ?? 0),
                'ron_equivalent_minor' => $ron,
            ]);

            $credited->fill([
                'gross_minor' => $credited->gross_minor - $gross,
                'withheld_minor' => $withheld === null ? null : (int) $credited->withheld_minor - $withheld,
                'net_minor' => $credited->net_minor - ($gross - ($withheld ?? 0)),
                'ron_equivalent_minor' => $ron === null ? null : (int) $credited->ron_equivalent_minor - $ron,
            ])->save();
        }

        return $closed;
    }

    /**
     * A number of units without the trailing zeros `bcsub()` pads it with.
     *
     * @param  numeric-string  $units
     * @return numeric-string
     */
    private static function plain(string $units): string
    {
        $trimmed = str_contains($units, '.') ? rtrim(rtrim($units, '0'), '.') : $units;
        assert(is_numeric($trimmed));

        return $trimmed;
    }
}
