<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\DataTransferObjects\PayoutTerms;
use Kern\Investments\Enums\PayoutKind;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;

/**
 * The reminders before a coupon or a repayment due today and not yet sent — the deposits' maturity
 * reminders (`FindDueMaturityReminders`), for holdings: the same distances, the same daily task, sent once
 * per account, payout and distance.
 *
 * For every investments account holding open lots of an instrument whose coupon or repayment is paid
 * exactly one of the distances from today. A lot sold is not reminded of; a dividend is not either — it is
 * announced by the company, not scheduled by the terms. Across every taxpayer: this runs from the
 * scheduler, where no tenant is active.
 */
final readonly class FindDueHoldingReminders
{
    public function __construct(private MarketData $market)
    {
    }

    /**
     * @param  list<int>  $distances  days before the payment, 0 for the day itself
     * @return list<array{account_id: string, subject_id: string, payout: PayoutTerms, days: int}>
     */
    public function __invoke(array $distances, ?CarbonImmutable $today = null): array
    {
        if ($distances === []) {
            return [];
        }

        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        /** @var list<string> $instrumentIds */
        $instrumentIds = InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->distinct()->pluck('instrument_id')->all();
        $due = [];

        foreach ($this->market->payouts($instrumentIds, $today->toDateTimeImmutable(), $today->addDays(max($distances))->toDateTimeImmutable()) as $payout) {
            $days = (int) $today->diffInDays(CarbonImmutable::instance($payout->paymentDate));

            if ($payout->kind === PayoutKind::DIVIDEND || ! in_array($days, $distances, true)) {
                continue;
            }

            $holders = InvestmentLot::query()->withoutGlobalScope(TenantScope::class)
                ->where('instrument_id', $payout->instrumentId)
                ->whereNull('sold_on')
                ->distinct()
                ->get(['financial_account_id', 'tax_subject_id']);

            foreach ($holders as $lot) {
                $sent = DB::table('investment_payout_reminders')
                    ->where('financial_account_id', $lot->financial_account_id)
                    ->where('payout_id', $payout->id)
                    ->where('days_before', $days)
                    ->exists();

                if (! $sent) {
                    $due[] = ['account_id' => $lot->financial_account_id, 'subject_id' => $lot->tax_subject_id, 'payout' => $payout, 'days' => $days];
                }
            }
        }

        return $due;
    }

    /** Records one reminder as sent, so the next run of the task leaves it alone. */
    public function markSent(string $accountId, string $subjectId, PayoutTerms $payout, int $days): void
    {
        DB::table('investment_payout_reminders')->insertOrIgnore([
            'financial_account_id' => $accountId,
            'payout_id' => $payout->id,
            'tax_subject_id' => $subjectId,
            'days_before' => $days,
            'sent_at' => now(),
        ]);
    }
}
