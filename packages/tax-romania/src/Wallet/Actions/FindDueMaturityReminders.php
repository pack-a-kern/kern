<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Wallet\Models\DepositTerms;

/**
 * The maturity reminders due today and not yet sent: for each distance in `wallet.deposit.reminder_days`,
 * every deposit maturing exactly that many days from today with no reminder for that distance on record.
 *
 * Across every taxpayer — this runs from the scheduler, where no tenant is active. A deposit closed early
 * is not reminded of; one closed on its maturity day still is, because the interest task closes matured
 * deposits before this runs, and "it matures today" is exactly the day it closed.
 */
final readonly class FindDueMaturityReminders
{
    /**
     * @param  list<int>  $distances  days before maturity, 0 for the day itself
     * @return list<array{terms: DepositTerms, days: int}>
     */
    public function __invoke(array $distances, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $due = [];

        foreach ($distances as $days) {
            /** @var list<DepositTerms> $terms */
            $terms = DepositTerms::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereDate('maturity_at', $today->addDays($days)->toDateString())
                ->where(static fn ($query) => $query->whereNull('closed_at')->orWhereColumn('closed_at', '>=', 'maturity_at'))
                ->whereNotExists(static fn ($query) => $query
                    ->select(DB::raw(1))
                    ->from('deposit_maturity_reminders')
                    ->whereColumn('deposit_maturity_reminders.account_id', 'deposit_terms.account_id')
                    ->where('deposit_maturity_reminders.days_before', $days))
                ->orderBy('maturity_at')
                ->get()
                ->all();

            foreach ($terms as $deposit) {
                $due[] = ['terms' => $deposit, 'days' => $days];
            }
        }

        return $due;
    }

    /** Records one reminder as sent, so the next run of the task leaves it alone. */
    public function markSent(DepositTerms $terms, int $days): void
    {
        DB::table('deposit_maturity_reminders')->insertOrIgnore([
            'account_id' => $terms->account_id,
            'tax_subject_id' => $terms->tax_subject_id,
            'days_before' => $days,
            'sent_at' => now(),
        ]);
    }
}
