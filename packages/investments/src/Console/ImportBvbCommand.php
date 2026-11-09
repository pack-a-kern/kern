<?php

declare(strict_types=1);

namespace Kern\Investments\Console;

use Illuminate\Console\Command;
use Kern\Investments\Services\BvbImport;

/** BVB's day (ADR 0025 §5): every weekday after the close — a day the exchange is closed adds nothing. */
final class ImportBvbCommand extends Command
{
    protected $signature = 'kern:investments:import-bvb';

    protected $description = 'Read BVB\'s shares, bonds and dividends, and each instrument\'s price for the day.';

    public function handle(BvbImport $import): int
    {
        foreach ($import->run() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
