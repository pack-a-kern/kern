<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Actions;

/** What a reading of the fiscal values found, for the task's own line in the run history. */
final readonly class ReadFiscalParametersResult
{
    public function __construct(
        public int $minimumWages,
        public int $candidates,
    ) {
    }
}
