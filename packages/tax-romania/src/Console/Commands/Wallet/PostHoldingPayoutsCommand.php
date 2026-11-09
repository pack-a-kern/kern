<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Wallet;

use Illuminate\Console\Command;
use Kern\TaxRomania\Wallet\Actions\PostHoldingPayouts;

/**
 * Credits the holdings what their instruments have paid (`holdings.post_payouts`, daily): coupons,
 * dividends and repayments whose day has come — see {@see PostHoldingPayouts}.
 *
 * What it could not credit it says, by why — a dividend with no approved rate is the one a person can
 * act on — and the next run tries again. Safe to run repeatedly: a payout already credited is not
 * credited twice.
 */
final class PostHoldingPayoutsCommand extends Command
{
    protected $signature = 'kern:wallet:post-holding-payouts';

    protected $description = 'Credit holdings the coupons, dividends and repayments that have been paid.';

    public function handle(PostHoldingPayouts $post): int
    {
        $result = $post();

        $this->info(sprintf('%d payout(s) credited.', $result->posted));

        foreach ($result->skipped as $reason => $count) {
            $this->warn(sprintf('%d payout(s) not credited: %s.', $count, $reason));
        }

        return self::SUCCESS;
    }
}
