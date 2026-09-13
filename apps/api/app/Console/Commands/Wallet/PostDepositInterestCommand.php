<?php

declare(strict_types=1);

namespace App\Console\Commands\Wallet;

use App\Domain\Wallet\Actions\PostMaturedDepositInterest;
use Illuminate\Console\Command;

/**
 * Posts every deposit interest period that has now accrued as a transaction.
 *
 * Scheduled rather than triggered by the deposit, because maturity is a date passing rather than an
 * action anyone takes: the schedule is written when the deposit is opened, and months later nothing
 * is running that would notice a period closed. See {@see PostMaturedDepositInterest} for why the
 * interest becomes a transaction at all.
 *
 * Safe to run repeatedly — a period that already produced a transaction is skipped, so a missed day
 * is caught up by the next run rather than paid twice.
 */
final class PostDepositInterestCommand extends Command
{
    protected $signature = 'taxos:wallet:post-deposit-interest';

    protected $description = 'Post matured deposit interest periods as transactions.';

    public function handle(PostMaturedDepositInterest $post): int
    {
        $posted = $post();

        $this->info(sprintf('%d interest period(s) posted.', $posted));

        return self::SUCCESS;
    }
}
