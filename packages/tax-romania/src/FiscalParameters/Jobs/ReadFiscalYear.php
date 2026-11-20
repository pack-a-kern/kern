<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\TaxRomania\FiscalParameters\Actions\StartFiscalYear;
use Kern\TaxRomania\FiscalParameters\FiscalParameterDecisionException;
use Kern\TaxRomania\Operations\Events\OperationsActivityChanged;

/**
 * One year's law read, on the queue.
 *
 * A reading asks a model about each value — some thirty seconds a value, several minutes a year —
 * which is more than a request may take and more than a person should watch. The screen asks for a
 * year and is answered at once; the candidates appear as the job finishes them.
 *
 * Unique per year while it runs, so asking twice for the same year does not read it twice. A year
 * whose law says nothing at all is not a failure: nothing is proposed, and the job ends.
 */
final class ReadFiscalYear implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** Long enough for a year of values to be read, one model call at a time. */
    public int $timeout = 3600;

    /**
     * How long the year is held against a second reading — the same as the job may run for.
     *
     * A job killed mid-reading, by a deploy or by a worker restart, never releases the lock it
     * took. Without an end to it, that year could never be read again: the screen would ask, the
     * request would be accepted, and the job would be dropped on dispatch with nothing to show for
     * it. It ends when the job itself must.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $year)
    {
        $this->onQueue(config()->string('legislation.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->year;
    }

    public function handle(StartFiscalYear $start): void
    {
        // This job is not an operation task, so it records no run in the ledger the topbar reads. It
        // nudges the indicator itself instead — once as it begins and once as it ends — so a reading
        // that takes minutes shows as work in flight the whole time rather than leaving the bar idle.
        OperationsActivityChanged::dispatch();

        try {
            $start($this->year);
        } catch (FiscalParameterDecisionException) {
            // The law of that year says none of the values: nothing to propose, nothing to retry.
        } finally {
            OperationsActivityChanged::dispatch();
        }
    }
}
