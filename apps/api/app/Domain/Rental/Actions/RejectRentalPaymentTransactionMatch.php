<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\Enums\TransactionMatchStatus;
use App\Domain\Rental\Exceptions\InvalidTransactionMatchTransitionException;
use App\Domain\Rental\Models\RentalPayment;

/**
 * Rejects a `PROPOSED` transaction match. `transaction_id` is deliberately left in place — the
 * rejected candidate stays visible for audit, matching `App\Domain\Documents\Models\
 * DocumentExtraction`'s identical precedent of keeping `result_ciphertext` regardless of the
 * review decision. Only `transaction_match_status` changes.
 */
final readonly class RejectRentalPaymentTransactionMatch
{
    public function __invoke(RentalPayment $payment): RentalPayment
    {
        if ($payment->transaction_match_status !== TransactionMatchStatus::PROPOSED) {
            throw InvalidTransactionMatchTransitionException::forStatus($payment->transaction_match_status);
        }

        $payment->transaction_match_status = TransactionMatchStatus::REJECTED;
        $payment->save();

        return $payment;
    }
}
