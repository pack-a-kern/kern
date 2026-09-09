<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Domain\Wallet\Models\Deposit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deposit>
 *
 * Builds a bare `deposits` row only — no `deposit_rate_periods` (a deposit's rate coverage is a
 * cross-table invariant `App\Domain\Wallet\Services\Interest\DepositRateCoverageValidator`
 * enforces, not something a factory state should fabricate silently). Tests that need a fully
 * interest-schedulable deposit create its rate period(s) explicitly alongside it, exactly as
 * `App\Domain\Wallet\Actions\OpenDeposit` does.
 */
final class DepositFactory extends Factory
{
    protected $model = Deposit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'principal_minor' => 10_000_00,
            'currency_code' => 'RON',
            'opened_at' => '2026-01-01',
            'maturity_at' => '2027-01-01',
            'rate_type' => RateType::FIXED,
            'interest_method' => InterestMethod::SIMPLE,
            'payment_frequency' => PaymentFrequency::AT_MATURITY,
            'day_count_convention' => DayCountConvention::ACTUAL_365,
            'auto_renewal' => false,
        ];
    }
}
