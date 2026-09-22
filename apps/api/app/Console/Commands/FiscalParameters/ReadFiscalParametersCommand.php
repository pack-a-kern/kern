<?php

declare(strict_types=1);

namespace App\Console\Commands\FiscalParameters;

use App\Domain\TaxKnowledge\FiscalParameters\Actions\ReadFiscalParameters;
use Illuminate\Console\Command;

/**
 * Reads every fiscal value there is to read — the minimum-wage decisions and ANAF's D212 structure
 * files — looks each up in the law, and proposes what the law says as candidates to approve.
 */
final class ReadFiscalParametersCommand extends Command
{
    /** @var string */
    protected $signature = 'taxos:fiscal-parameters:read
        {--decisions-only : Read only the minimum-wage decisions, not the D212 files}';

    /** @var string */
    protected $description = 'Read the fiscal values from the minimum-wage decisions and the D212 files, and propose those the law says.';

    public function handle(ReadFiscalParameters $read): int
    {
        $result = $read(forms: $this->option('decisions-only') !== true);

        $this->components->info(sprintf(
            '%d minimum wages read, %d D212 files, %d candidates to review; %d values a form states that the law was not found to say.',
            $result->minimumWages,
            $result->forms,
            $result->candidates,
            $result->notInLaw,
        ));

        return self::SUCCESS;
    }
}
