<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Settings;

use Illuminate\Console\Command;
use Kern\Platform\Settings\Services\WatchSystemHealth;

/**
 * The scheduled look at every service (`WatchSystemHealth`): keeps what is down, and tells the people
 * who read the system status when that changes. Run by the operations schedule, every five minutes.
 */
final class WatchSystemHealthCommand extends Command
{
    protected $signature = 'kern:health:watch';

    protected $description = 'Probe every service, keep which ones are down, and broadcast it when that changes.';

    public function handle(WatchSystemHealth $watch): int
    {
        $down = $watch->watch();

        $this->line($down === [] ? 'Every service answered.' : 'Not answering: ' . implode(', ', $down));

        return self::SUCCESS;
    }
}
