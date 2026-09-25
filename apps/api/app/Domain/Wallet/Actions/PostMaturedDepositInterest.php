<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Models\DepositInterestEvent;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Services\DepositMovements;
use App\Domain\Wallet\Services\WalletAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Tenancy\TenantScope;

/**
 * Turns interest that has actually accrued into money that has actually arrived.
 *
 * ## Why a schedule is not a ledger
 *
 * `GenerateDepositInterestSchedule` projects every period of a deposit's life the moment it is
 * opened — including periods years away. Those are forecasts: nothing has happened, and posting a
 * transaction for money that has not arrived would be an invented movement.
 *
 * So the schedule stays a schedule, and this walks the part of it that is now in the past. A period
 * whose `accrual_to` has passed is interest the deposit has earned, and that earning is the same
 * kind of fact as any other money arriving — so it becomes a `financial_transactions` row like
 * everything else.
 *
 * ## Why that matters beyond tidiness
 *
 * Before this, "money I earned" had two shapes depending on where it came from: a transaction for a
 * rent payment, a `deposit_interest_events` row for interest. Every later step — classifying it,
 * turning it into a `TaxFact`, totalling a year — would have had to know about both, and the two
 * would have drifted. One ledger means one path.
 *
 * ## What is posted
 *
 * The **net** amount, because that is what arrived: the withholding is tax already paid at source,
 * and a transaction for the gross would claim money that never reached anyone. The gross and the
 * withheld figure stay on the interest event, which is where a tax fact will read them from —
 * `tax_facts` has `withheld_tax_minor` for exactly this.
 *
 * Written through {@see DepositMovements} rather than {@see RecordFinancialTransaction}, which refuses
 * a holding source by design. That refusal is about *typed* movements: a deposit's entries come from
 * its own terms, and this is those terms producing one — classified `INTEREST` as it is written,
 * because there is no question about what a deposit's interest is.
 *
 * Idempotent: a period that already carries a `transaction_id` is skipped, so running this twice
 * cannot pay the same interest twice.
 */
final readonly class PostMaturedDepositInterest
{
    public function __construct(
        private DepositMovements $movements,
        private WalletAudit $audit,
    ) {
    }

    /**
     * @return int how many periods were posted
     */
    public function __invoke(?CarbonImmutable $asOf = null): int
    {
        $today = ($asOf ?? CarbonImmutable::now('UTC'))->startOfDay();

        // Explicitly across every subject. This runs from the scheduler, where no `TenantContext`
        // is bound and none could be — a nightly task belongs to the installation, not to whoever
        // last made a request. Scoping it would silently find nothing.
        /** @var list<DepositInterestEvent> $due */
        $due = DepositInterestEvent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('transaction_id')
            ->whereDate('accrual_to', '<=', $today->toDateString())
            ->orderBy('accrual_to')
            ->get()
            ->all();

        $posted = 0;

        foreach ($due as $event) {
            DB::transaction(function () use ($event): void {
                $deposit = FinancialAccount::query()->withoutGlobalScope(TenantScope::class)->find($event->account_id);

                $transaction = $this->movements->write(
                    subjectId: $event->tax_subject_id,
                    accountId: $event->account_id,
                    // The day the period closed, not the day this ran: a run that happens late must
                    // not move the income into a different fiscal year.
                    bookedAt: $event->accrual_to->toDateString(),
                    amountMinor: $event->net_interest_minor,
                    currencyCode: $event->currency_code,
                    description: DepositMovements::nameOf($deposit),
                    category: DepositMovements::INTEREST,
                );

                $event->transaction_id = DepositMovements::id($transaction);
                $event->paid_at = $event->accrual_to;
                $event->save();
            });

            $this->audit->record(
                subjectId: $event->tax_subject_id,
                actorId: null,
                eventType: WalletAuditEvent::DEPOSIT_INTEREST_POSTED->value,
                resourceType: WalletAuditEvent::DEPOSIT_INTEREST_POSTED->resourceType(),
                resourceId: $event->transaction_id ?? $event->id,
                payload: [
                    'deposit_interest_event_id' => $event->id,
                    'net_interest_minor' => $event->net_interest_minor,
                    'currency_code' => $event->currency_code,
                ],
            );

            $posted++;
        }

        return $posted;
    }
}
