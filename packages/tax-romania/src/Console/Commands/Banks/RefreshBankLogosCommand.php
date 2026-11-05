<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Banks;

use Illuminate\Console\Command;
use Kern\TaxRomania\Banks\Logos\BankLogos;

final class RefreshBankLogosCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:banks:logos';

    /** @var string */
    protected $description = 'Read the bank logo folder again, after files were added or replaced.';

    public function handle(BankLogos $logos): int
    {
        $found = $logos->refresh();

        if (! $logos->available()) {
            $this->components->warn('No logo folder at ' . $logos->path() . ': create it, with a folder per country (RO, BG, …), or set BANKS_LOGOS_PATH.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Bank logos read from %s: %d found.', $logos->path(), count($found)));

        return self::SUCCESS;
    }
}
