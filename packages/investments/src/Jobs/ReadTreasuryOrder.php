<?php

declare(strict_types=1);

namespace Kern\Investments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Services\TreasuryOrders;

/** One order read for its issues (`TreasuryOrders`). Tried once: an order that does not read will not read on a second try. */
final class ReadTreasuryOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $sourceDocumentId,
        public readonly Programme $programme,
    ) {
    }

    public function handle(TreasuryOrders $orders): void
    {
        $orders->read($this->sourceDocumentId, $this->programme);
    }
}
