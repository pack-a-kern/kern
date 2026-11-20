<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\TaxRomania\FiscalParameters\Actions\ReadFiscalParameters;
use Kern\TaxRomania\Operations\Events\OperationsActivityChanged;

/**
 * A minimum-wage decision was just read in: its value proposed, beside the others, for a person to
 * approve.
 */
final class ReadMinimumWages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(config()->string('legislation.queue'));
    }

    public function handle(ReadFiscalParameters $read): void
    {
        // Not an operation task, so it nudges the topbar indicator itself — see ReadFiscalYear.
        OperationsActivityChanged::dispatch();

        try {
            $read();
        } finally {
            OperationsActivityChanged::dispatch();
        }
    }
}
