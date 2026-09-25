<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Banks;

use Illuminate\Console\Command;
use Kern\TaxRomania\Banks\Actions\ImportBanks;
use Throwable;

final class ImportBanksCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:banks:import';

    /** @var string */
    protected $description = 'Refresh the bank list from the ECB list of institutions, with BICs from GLEIF.';

    public function handle(ImportBanks $import): int
    {
        try {
            $summary = $import();
        } catch (Throwable $exception) {
            $this->components->error('Bank import failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Bank import complete: %d listed (ECB list of %s), %d new, %d no longer listed, %d without a BIC (GLEIF file of %s).',
            $summary->listed,
            $summary->listDate,
            $summary->created,
            $summary->deactivated,
            $summary->withoutBic,
            $summary->bicListDate,
        ));

        return self::SUCCESS;
    }
}
