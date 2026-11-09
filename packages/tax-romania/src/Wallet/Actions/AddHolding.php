<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Kern\Investments\Contracts\MarketData;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\TaxRomania\Wallet\DataTransferObjects\AddHoldingInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\HoldingRefusal;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Exceptions\HoldingRefusedException;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Services\Holdings\LotAmounts;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Adds a purchase to an investments account — the deposit's flow (ADR 0025 §9): the account chosen, the
 * instrument chosen from those on offer or trading, its currency taken from its terms; the person gives
 * only the units, the price per unit, the date, the fees and where it is held.
 *
 * **No duplicate.** A purchase that carries its broker's reference is found again by it — by its blind
 * index at the same place, never by decrypting — and the lot already there is answered, unchanged. That
 * is how a broker integrated later adopts what a person entered, rather than adding it a second time; the
 * unique index on (`tax_subject_id`, `held_at`, `external_ref_blind_index`) holds the same line in the
 * database.
 *
 * Tenant-scoped: an account of another taxpayer is not found.
 */
final readonly class AddHolding
{
    public function __construct(
        private MarketData $market,
        private BlindIndexHasher $blindIndex,
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(AddHoldingInput $input): InvestmentLot
    {
        $account = FinancialAccount::query()->findOrFail($input->accountId);

        if ($account->kind !== AccountKind::INVESTMENTS) {
            throw HoldingRefusedException::because(HoldingRefusal::NOT_AN_INVESTMENTS_ACCOUNT);
        }

        $instrument = $this->market->instruments([$input->instrumentId])[$input->instrumentId] ?? null;

        if ($instrument === null || ! in_array($instrument->status, [InstrumentStatus::ON_OFFER, InstrumentStatus::ACTIVE], true)) {
            throw HoldingRefusedException::because(HoldingRefusal::INSTRUMENT_NOT_OFFERED);
        }

        $index = $input->externalRef === null ? null : $this->blindIndex->compute(Normalizer::text($input->externalRef));
        $existing = $index === null ? null : InvestmentLot::query()
            ->where('held_at', $input->heldAt)
            ->where('external_ref_blind_index', $index)
            ->first();

        // The same position adopted again is the lot it already is; the reference on another account or
        // instrument is someone's mistake, and refused rather than answered with the wrong lot.
        if ($existing !== null) {
            return $existing->financial_account_id === $account->id && $existing->instrument_id === $instrument->id
                ? $existing
                : throw HoldingRefusedException::because(HoldingRefusal::REFERENCE_IN_USE);
        }

        if (! LotAmounts::withinCeiling($input->units, $input->pricePerUnit, $input->feesMinor)) {
            throw HoldingRefusedException::because(HoldingRefusal::AMOUNT_TOO_LARGE);
        }

        $lot = InvestmentLot::query()->create([
            'tax_subject_id' => $account->tax_subject_id,
            'financial_account_id' => $account->id,
            'instrument_id' => $instrument->id,
            'units' => $input->units,
            'price_per_unit' => $input->pricePerUnit,
            'bought_on' => $input->boughtOn,
            'fees_minor' => $input->feesMinor,
            'currency' => $instrument->currency,
            'held_at' => $input->heldAt,
            'external_ref_ciphertext' => $input->externalRef,
            'external_ref_blind_index' => $index,
        ]);

        $this->audit->record(
            subjectId: $lot->tax_subject_id,
            actorId: $input->actorId,
            eventType: WalletAuditEvent::HOLDING_ADDED->value,
            resourceType: WalletAuditEvent::HOLDING_ADDED->resourceType(),
            resourceId: $lot->id,
            payload: [
                'account_id' => $account->id,
                'instrument_id' => $instrument->id,
                'units' => $lot->units,
                'price_per_unit' => $lot->price_per_unit,
                'bought_on' => $input->boughtOn,
                'fees_minor' => $lot->fees_minor,
                'held_at' => $lot->held_at->value,
            ],
        );

        return $lot;
    }
}
