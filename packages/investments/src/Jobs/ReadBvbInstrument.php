<?php

declare(strict_types=1);

namespace Kern\Investments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Services\InstrumentHistory;
use Kern\Investments\Services\IssuerProfiles;

/**
 * One listed instrument's BVB page read in the background (`BvbPages`): its past prices the first time,
 * then its page — figures, calendar, shareholders. Tried once: one that does not read is queued again by
 * the next day's import, as still unread. Unique by instrument, so a reading still waiting is never queued
 * twice.
 */
final class ReadBvbInstrument implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $instrumentId,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->instrumentId;
    }

    public function handle(InstrumentHistory $history, IssuerProfiles $profiles): void
    {
        $instrument = FinancialInstrument::query()->findOrFail($this->instrumentId);

        if ($instrument->history_read_at === null) {
            $history->read($instrument);
        }

        $profiles->refresh((string) $instrument->symbol);
    }
}
