<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\Enums\TransactionMatchStatus;
use App\Domain\Rental\Models\RentalPayment;
use App\Domain\Rental\Services\Matching\TransactionMatchCandidateFinder;

/**
 * Runs the matching heuristic (`App\Domain\Rental\Services\Matching\
 * TransactionMatchCandidateFinder` — see that class's docblock for the exact signals) for one
 * `rental_payments` row and, if a candidate is found, records it as `PROPOSED`. **Never writes
 * `CONFIRMED`** — `BLUEPRINT.md` §14.6's "never auto-confirm" principle applies to this financial
 * link exactly as it does to a document extraction; only
 * `App\Domain\Rental\Actions\ConfirmRentalPaymentTransactionMatch`, a human-invoked decision, can
 * do that.
 */
final readonly class ProposeRentalPaymentTransactionMatch
{
    public function __construct(
        private TransactionMatchCandidateFinder $candidateFinder,
    ) {
    }

    public function __invoke(RentalPayment $payment): RentalPayment
    {
        $candidate = $this->candidateFinder->findBest(
            taxSubjectId: $payment->tax_subject_id,
            amountMinor: $payment->amount_minor,
            currencyCode: $payment->currency_code,
            paidAt: $payment->paid_at->toImmutable(),
        );

        if ($candidate === null) {
            return $payment;
        }

        $payment->transaction_id = $candidate->transactionId;
        $payment->transaction_match_status = TransactionMatchStatus::PROPOSED;
        $payment->save();

        return $payment;
    }
}
