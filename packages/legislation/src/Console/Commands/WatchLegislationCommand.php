<?php

declare(strict_types=1);

namespace Kern\Legislation\Console\Commands;

use Illuminate\Console\Command;
use Kern\Legislation\Watches\RunLawWatches;
use Kern\Legislation\Watches\WatchSettings;

/** The hourly `legislation.watch` task: every watch due run, a line each. Fails when one did, saying which. */
final class WatchLegislationCommand extends Command
{
    protected $signature = 'kern:legislation:watch';

    protected $description = 'Run every law watch that is due: each new act its search finds is read as a law.';

    public function handle(RunLawWatches $run): int
    {
        $lines = $run(WatchSettings::read($this->laravel->make('config')));

        foreach ($lines as $line) {
            $this->line($line);
        }

        return array_filter($lines, static fn (string $line): bool => str_contains($line, 'FAILED')) === [] ? self::SUCCESS : self::FAILURE;
    }
}
