<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Wallet;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Notices\DepositMaturityNotice;
use Kern\TaxRomania\Notices\HoldingPayoutNotice;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Wallet\Actions\FindDueHoldingReminders;
use Kern\TaxRomania\Wallet\Actions\FindDueMaturityReminders;

/**
 * The daily maturity reminders (`wallet.maturity_reminders`, at the hour its cron sets): every deposit
 * at one of the distances in `wallet.deposit.reminder_days`, and every holding whose coupon or repayment
 * is, told once to its taxpayer's owners.
 *
 * Here because it joins the deposits with the taxpayer's owners, who are another module's. Each
 * reminder is recorded as sent before the next is looked at, so a run cut short resumes where it
 * stopped and sends nothing twice.
 */
final class RemindMaturingDepositsCommand extends Command
{
    protected $signature = 'kern:wallet:remind-maturities';

    protected $description = 'Tell taxpayers\' owners about deposits and holdings nearing a maturity or a coupon, once per distance.';

    public function handle(FindDueMaturityReminders $reminders, FindDueHoldingReminders $holdings): int
    {
        /** @var list<int> $distances */
        $distances = config('wallet.deposit.reminder_days', []);
        $due = $reminders($distances);
        $dueHoldings = $holdings($distances);

        foreach ($due as ['terms' => $terms, 'days' => $days]) {
            $owners = $this->owners($terms->tax_subject_id);

            if ($owners->isNotEmpty()) {
                Notification::send($owners, DepositMaturityNotice::about($terms, $days));
            }

            $reminders->markSent($terms, $days);
        }

        foreach ($dueHoldings as ['account_id' => $account, 'subject_id' => $subject, 'payout' => $payout, 'days' => $days]) {
            $owners = $this->owners($subject);

            if ($owners->isNotEmpty()) {
                Notification::send($owners, HoldingPayoutNotice::about($subject, $account, $payout, $days));
            }

            $holdings->markSent($account, $subject, $payout, $days);
        }

        $this->info(sprintf('%d maturity reminder(s) sent, %d for holdings.', count($due), count($dueHoldings)));

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function owners(string $subjectId): Collection
    {
        return User::query()
            ->whereIn('id', SubjectMembership::query()->owned()->where('subject_id', $subjectId)->select('user_id'))
            ->get();
    }
}
