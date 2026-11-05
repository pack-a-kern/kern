<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Wallet;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Banks\Models\Bank;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Wallet\Actions\FindDueMaturityReminders;
use Kern\TaxRomania\Wallet\Notifications\DepositMaturityNotice;

/**
 * The daily maturity reminders (`wallet.maturity_reminders`, at the hour its cron sets): every deposit
 * at one of the distances in `wallet.deposit.reminder_days`, told once to its taxpayer's owners.
 *
 * Here because it joins three modules — the deposits, the banks they are at, the taxpayer's owners —
 * and the Wallet module is not one that reads Banks. Each reminder is recorded as sent before the next
 * is looked at, so a run cut short resumes where it stopped and sends nothing twice.
 */
final class RemindMaturingDepositsCommand extends Command
{
    protected $signature = 'kern:wallet:remind-maturities';

    protected $description = 'Tell taxpayers\' owners about deposits nearing or at maturity, once per distance.';

    public function handle(FindDueMaturityReminders $reminders): int
    {
        /** @var list<int> $distances */
        $distances = config('wallet.deposit.reminder_days', []);
        $due = $reminders($distances);

        foreach ($due as ['terms' => $terms, 'days' => $days]) {
            $bank = $terms->bank_id === null ? null : Bank::query()->find($terms->bank_id);

            $owners = User::query()
                ->whereIn('id', SubjectMembership::query()->owned()->where('subject_id', $terms->tax_subject_id)->select('user_id'))
                ->get();

            if ($owners->isNotEmpty()) {
                Notification::send($owners, new DepositMaturityNotice($terms, $days, $bank?->name));
            }

            $reminders->markSent($terms, $days);
        }

        $this->info(sprintf('%d maturity reminder(s) sent.', count($due)));

        return self::SUCCESS;
    }
}
