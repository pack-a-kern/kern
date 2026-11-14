<?php

declare(strict_types=1);

namespace Kern\Investments\Console;

use Illuminate\Console\Command;
use Kern\Investments\Services\BvbImport;
use Kern\Investments\Services\BvbPages;

/**
 * BVB's day (ADR 0025 §5): every weekday after the close — a day the exchange is closed adds nothing. Then
 * the instruments whose page is due a reading are queued, to be read one at a time in the background.
 */
final class ImportBvbCommand extends Command
{
    protected $signature = 'kern:investments:import-bvb';

    protected $description = 'Read BVB\'s shares, bonds and dividends and each instrument\'s price for the day, then queue the pages due a reading.';

    public function handle(BvbImport $import, BvbPages $pages): int
    {
        foreach ($import->run() as $line) {
            $this->line($line);
        }

        $this->line($pages->queue());

        return self::SUCCESS;
    }
}
