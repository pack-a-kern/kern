<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\DataTransferObjects\RecordRentalPaymentInput;
use App\Domain\Rental\Enums\AccrualStatus;
use App\Domain\Rental\Exceptions\InvalidPaymentAmountException;
use App\Domain\Rental\Models\RentalAccrual;
use App\Domain\Rental\Models\RentalPayment;
use Illuminate\Support\Facades\DB;

/**
 * Records one real-world `rental_payments` row — never linked to a wallet transaction at creation
 * time (`transaction_match_status` starts `UNMATCHED`; see `App\Domain\Rental\Actions\
 * ProposeRentalPaymentTransactionMatch` for that separate step). If `accrualId` is given, the
 * accrual is marked `PAID` once the sum of every payment recorded against it reaches (or exceeds)
 * its own `gross_minor` — a partial payment leaves it `PENDING`, deliberately: this task tracks
 * whether an accrual is fully settled, not a payment plan (`App\Domain\Rental\Enums\
 * AccrualStatus`'s own docblock).
 */
final readonly class RecordRentalPayment
{
    public function __invoke(RecordRentalPaymentInput $input): RentalPayment
    {
        if ($input->amountMinor <= 0) {
            throw InvalidPaymentAmountException::forAmount($input->amountMinor);
        }

        return DB::transaction(function () use ($input): RentalPayment {
            $payment = RentalPayment::create([
                'tax_subject_id' => $input->taxSubjectId,
                'rental_contract_id' => $input->rentalContractId,
                'accrual_id' => $input->accrualId,
                'amount_minor' => $input->amountMinor,
                'currency_code' => strtoupper($input->currencyCode),
                'paid_at' => $input->paidAt,
            ]);

            if ($input->accrualId !== null) {
                $this->reconcileAccrual($input->accrualId);
            }

            return $payment;
        });
    }

    private function reconcileAccrual(string $accrualId): void
    {
        /** @var RentalAccrual $accrual */
        $accrual = RentalAccrual::query()->findOrFail($accrualId);

        if ($accrual->status === AccrualStatus::PAID) {
            return;
        }

        $totalPaidMinor = (int) RentalPayment::query()
            ->where('accrual_id', $accrualId)
            ->sum('amount_minor');

        if ($totalPaidMinor >= $accrual->gross_minor) {
            $accrual->status = AccrualStatus::PAID;
            $accrual->save();
        }
    }
}
