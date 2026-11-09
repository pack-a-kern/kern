<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\Enums\Programme;
use Kern\TaxRomania\Wallet\DataTransferObjects\SellHoldingInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\HoldingRefusal;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Exceptions\HoldingRefusedException;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Models\InvestmentLotPayout;
use Kern\TaxRomania\Wallet\Services\DepositMovements;
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
 * **What is written.** The sale fees are shared among the lots sold by units; the money received —
 * units × price, less the fees — arrives in the account as one `TRANSFER`, on the day of the sale. The
 * result is the difference between that and what the units cost, and is computed from the rows whenever
 * it is shown; the tax on it is the tax rules' (ADR 0025 §10).
 */
final readonly class SellHolding
{
    public function __construct(
        private MarketData $market,
        private DepositMovements $movements,
        private WalletAudit $audit,
    ) {
    }

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

            if ($instrument === null || bccomp($held, $input->units, 6) < 0) {
                throw HoldingRefusedException::because(HoldingRefusal::NOT_ENOUGH_UNITS);
            }

            $left = $input->units;
            $feesLeft = $input->feesMinor;
            $closed = [];

            foreach ($open as $lot) {
                if (bccomp($left, '0', 6) <= 0) {
                    break;
                }

                $take = bccomp($lot->units, $left, 6) <= 0 ? $lot->units : self::plain($left);
                $left = bcsub($left, $take, 6);
                // The last lot takes what is left of the fees, so their parts add up to them exactly.
                $fees = bccomp($left, '0', 6) === 0 ? $feesLeft : LotAmounts::share($input->feesMinor, $take, $input->units);
                $feesLeft -= $fees;
                $closed[] = $this->close($lot, $take, $input, $fees);
            }

            $received = LotAmounts::minor($input->units, $input->pricePerUnit) - $input->feesMinor;

            if ($received !== 0) {
                $this->movements->write(
                    subjectId: $account->tax_subject_id,
                    accountId: $account->id,
                    bookedAt: $input->soldOn,
                    amountMinor: $received,
                    currencyCode: $instrument->currency,
                    description: $instrument->name,
                    category: DepositMovements::TRANSFER,
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
