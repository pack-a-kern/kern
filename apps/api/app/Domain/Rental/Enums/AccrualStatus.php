<?php

declare(strict_types=1);

namespace App\Domain\Rental\Enums;

/**
 * `rental_accruals.status`. `PENDING` until `App\Domain\Rental\Actions\RecordRentalPayment` links
 * enough paid `rental_payments` to reach the accrual's own `gross_minor`, at which point it
 * becomes `PAID` and is never recalculated again by
 * `App\Domain\Rental\Actions\GenerateRentalAccruals` (the same "a settled record is immutable"
 * rule `App\Domain\Wallet\Actions\GenerateDepositInterestSchedule` applies to a `paid_at` deposit
 * interest event). No `PARTIALLY_PAID`/`OVERDUE` case: this task tracks whether an accrual is
 * fully settled, not payment-plan bookkeeping or due-date alerting, neither of which was asked
 * for — adding either now would be speculative scope (`docs/CODE-QUALITY.md` §2).
 */
enum AccrualStatus: string
{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
}
