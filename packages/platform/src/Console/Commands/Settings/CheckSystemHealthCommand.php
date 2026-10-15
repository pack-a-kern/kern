<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Settings;

use Illuminate\Console\Command;
use Kern\Platform\Settings\Services\ProbeSystemHealth;

/**
 * `php artisan kern:health` — the same probes Settings → System status runs, on a terminal.
 *
 * ## Why a command and not a request to the health endpoint
 *
 * `GET /api/v1/settings/health` needs a signed-in administrator holding `system.settings.view`, and
 * the installer runs before any account exists. `scripts/install.sh` ends on this command: the last
 * thing an install does is ask every service whether it is really there, from inside the container
 * that has to reach it — which is the only vantage point whose answer means anything. A probe run
 * from the host would be testing the host's network, not the application's.
 *
 * It reuses {@see ProbeSystemHealth} unchanged, so the terminal and the screen can never disagree
 * about what is up.
 *
 * ## Why `not_configured` exits 0 and `degraded` does not
 *
 * `COMPOSE_FILE` lets an installation leave out the processing plane and the browser worker, and an
 * installer that then failed would be failing on a decision the operator had just made deliberately.
 * A `degraded` row is the opposite: something this installation was told to run, asked, and did not
 * get an answer from. That is the one outcome worth a non-zero exit, because it is the one an
 * operator has to act on before the installation is finished.
 */
final class CheckSystemHealthCommand extends Command
{
    protected $signature = 'kern:health';

    protected $description = 'Probe every service this installation runs, and exit non-zero if one of them is down.';

    public function handle(ProbeSystemHealth $probe): int
    {
        $components = $probe();

        $width = 0;

        foreach ($components as $component) {
            $width = max($width, strlen($component->key));
        }

        $down = [];

        foreach ($components as $component) {
            // The detail is what was actually checked — the driver's name, the KEK's version, the
            // exception class a failure stopped on — so a row reading "down" says which layer did.
            $line = sprintf('  %-' . $width . 's  %-13s %s', $component->key, match ($component->state) {
                'healthy' => 'ok',
                'not_configured' => 'not installed',
                default => 'DOWN',
            }, $component->detail ?? '');

            if ($component->state === 'degraded') {
                $down[] = $component->key;
                $this->line('<fg=red>' . rtrim($line) . '</>');

                continue;
            }

            $this->line(rtrim($line));
        }

        if ($down !== []) {
            $this->newLine();
            $this->error(sprintf(
                'Did not answer: %s. The cause is in the application log — this line names the layer, never the address it was talking to (BLUEPRINT.md §4.10).',
                implode(', ', $down),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
