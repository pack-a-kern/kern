<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Jobs;

use App\Domain\TaxKnowledge\FiscalParameters\Actions\ReadFiscalParameters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A minimum-wage decision was just read in: its value proposed, beside the others, for a person to
 * approve. Only the decisions are read — the forms have not changed.
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
        $read(rules: false);
    }
}
