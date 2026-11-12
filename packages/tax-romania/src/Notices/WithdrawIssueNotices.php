<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Notices;

use Kern\Investments\Events\InstrumentsWithdrawn;
use Kern\Notifications\Actions\WithdrawNotices;

/**
 * Issues deleted with the order they were read from take their "on offer" notices with them, read or not:
 * a notice whose issue is gone shows nothing, and is not left behind in anyone's bell.
 */
final readonly class WithdrawIssueNotices
{
    public function __construct(private WithdrawNotices $withdraw)
    {
    }

    public function handle(InstrumentsWithdrawn $event): void
    {
        ($this->withdraw)(IssueOnOfferNotice::key(), IssueOnOfferNotice::INSTRUMENT, $event->instrumentIds);
    }
}
