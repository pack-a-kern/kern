<?php

declare(strict_types=1);

namespace App\Console\Commands\FiscalParameters;

use App\Domain\TaxKnowledge\FiscalParameters\Actions\ReadFiscalParameters;
use Illuminate\Console\Command;

/**
 * Reads every fiscal value there is to read — the minimum-wage decisions, and the Codul fiscal
 * itself for every other value — and proposes what it read as candidates to approve.
 */
final class ReadFiscalParametersCommand extends Command
{
    /** @var string */
    protected $signature = 'taxos:fiscal-parameters:read
        {--decisions-only : Read only the minimum-wage decisions, not the law}';

    /** @var string */
    protected $description = 'Read the minimum-wage decisions and the law, and propose every value read as a candidate.';

    public function handle(ReadFiscalParameters $read): int
    {
        $result = $read(rules: $this->option('decisions-only') !== true);

        $this->components->info(sprintf(
            '%d minimum wages read, %d candidates to review.',
            $result->minimumWages,
            $result->candidates,
        ));

        return self::SUCCESS;
    }
}
