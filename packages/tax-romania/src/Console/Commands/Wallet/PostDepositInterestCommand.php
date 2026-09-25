<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Wallet;

use Illuminate\Console\Command;
use Kern\TaxRomania\Wallet\Actions\CloseMaturedDeposits;
use Kern\TaxRomania\Wallet\Actions\PostMaturedDepositInterest;

/**
 * Settles deposits: posts every interest period that has now accrued, then closes every deposit that
 * has reached its maturity.
 *
 * The name predates the closing half and is kept, because the operations screen stores whether a
 * task is enabled against it — renaming would silently re-enable a task an operator had paused.
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
    protected $signature = 'kern:wallet:post-deposit-interest';

    protected $description = 'Post accrued deposit interest and close matured deposits.';

    public function handle(PostMaturedDepositInterest $post, CloseMaturedDeposits $close): int
    {
        // Interest first: a deposit's last period ends on its maturity, and closing moves the
        // balance that period adds to — closed before it posted, that interest would be stranded.
        $posted = $post();
        $closed = $close();

        $this->info(sprintf('%d interest period(s) posted, %d deposit(s) closed.', $posted, $closed));

        return self::SUCCESS;
    }
}
