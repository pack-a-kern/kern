<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\Enums\TransactionMatchStatus;
use App\Domain\Rental\Exceptions\InvalidTransactionMatchTransitionException;
use App\Domain\Rental\Models\RentalPayment;

/**
 * The one sanctioned way to turn a `PROPOSED` transaction match into a `CONFIRMED` one — a human
 * decision, never automatic (`BLUEPRINT.md` §14.6).
 */
final readonly class ConfirmRentalPaymentTransactionMatch
{
    public function __invoke(RentalPayment $payment): RentalPayment
    {
        if ($payment->transaction_match_status !== TransactionMatchStatus::PROPOSED) {
            throw InvalidTransactionMatchTransitionException::forStatus($payment->transaction_match_status);
        }

        $payment->transaction_match_status = TransactionMatchStatus::CONFIRMED;
        $payment->save();

        return $payment;
    }
}
