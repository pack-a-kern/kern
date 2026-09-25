<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Models\DepositTerms;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Services\DepositMovements;
use App\Domain\Wallet\Services\WalletAudit;
use App\Support\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ends every deposit that has reached its maturity, and sends its money back where it came from.
 *
 * This application offers no auto-renewal, so maturity is an ending, not a decision anybody makes —
 * and it has to be settled, or the money disappears: the deposit leaves the wallet total the moment
 * it is over, while the transaction that funded it stays on the funding account for good.
 *
 * ## What settling writes
 *
 * - The interest the deposit accumulated — posted to the deposit itself as it accrued — moves out of
 *   it, so a closed deposit holds nothing.
 * - The principal and that interest arrive in the funding account, as one `TRANSFER`, dated to the
 *   maturity rather than to the night this ran: a settlement caught up a week late must not move
 *   money into a different month.
 *
 * The interest was already recognised as `INTEREST` when it was posted; moving it afterwards is a
 * transfer between the taxpayer's own accounts, and counting it again would double the income.
 *
 * A deposit funded from an account this application does not track settles the same way minus the
 * arrival: its money goes back to where it came from, which is nowhere here.
 *
 * Runs after {@see PostMaturedDepositInterest}, so the last period is on the ledger before the
 * balance it contributes to is moved. Idempotent: `closed_at` marks a settled deposit, and a closed
 * deposit is never selected again.
 */
final readonly class CloseMaturedDeposits
{
    public function __construct(
        private DepositMovements $movements,
        private WalletAudit $audit,
    ) {
    }

    /**
     * @return int how many deposits were closed
     */
    public function __invoke(?CarbonImmutable $asOf = null): int
    {
        $today = ($asOf ?? CarbonImmutable::now('UTC'))->startOfDay();

        // Across every subject: this runs from the scheduler, where no `TenantContext` exists.
        /** @var list<DepositTerms> $due */
        $due = DepositTerms::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('closed_at')
            ->whereDate('maturity_at', '<=', $today->toDateString())
            ->orderBy('maturity_at')
            ->get()
            ->all();

        foreach ($due as $terms) {
            $interest = DB::transaction(fn (): int => $this->settle($terms));

            $this->audit->record(
                subjectId: $terms->tax_subject_id,
                actorId: null,
                eventType: WalletAuditEvent::DEPOSIT_CLOSED->value,
                resourceType: WalletAuditEvent::DEPOSIT_CLOSED->resourceType(),
                resourceId: $terms->account_id,
                payload: [
                    'principal_minor' => $terms->principal_minor,
                    'interest_minor' => $interest,
                    'returned_to' => $terms->funding_account_id ?? '',
                ],
            );
        }

        return count($due);
    }

    /**
     * @return int the interest that was moved out of the deposit
     */
    private function settle(DepositTerms $terms): int
    {
        $deposit = FinancialAccount::query()->withoutGlobalScope(TenantScope::class)->find($terms->account_id);
        $name = DepositMovements::nameOf($deposit);
        $maturity = $terms->maturity_at->toDateString();

        // Everything posted to the deposit is interest: its principal is declared on its terms, not
        // recorded against it, so the deposit account's own rows are exactly what it earned.
        $interest = (int) FinancialTransaction::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('account_id', $terms->account_id)
            ->sum('amount_minor');

        if ($interest !== 0) {
            $this->movements->write(
                subjectId: $terms->tax_subject_id,
                accountId: $terms->account_id,
                bookedAt: $maturity,
                amountMinor: -$interest,
                currencyCode: $terms->currency_code,
                description: $name,
                category: DepositMovements::TRANSFER,
            );
        }

        if ($terms->funding_account_id !== null) {
            $settlement = $this->movements->write(
                subjectId: $terms->tax_subject_id,
                accountId: $terms->funding_account_id,
                bookedAt: $maturity,
                amountMinor: $terms->principal_minor + $interest,
                currencyCode: $terms->currency_code,
                description: $name,
                category: DepositMovements::TRANSFER,
            );

            $terms->settlement_transaction_id = DepositMovements::id($settlement);
        }

        $terms->closed_at = $terms->maturity_at;
        $terms->save();

        return $interest;
    }
}
