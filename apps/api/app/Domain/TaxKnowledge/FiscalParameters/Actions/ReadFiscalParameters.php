<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Actions;

use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterDefinition;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\LawValueReader;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\MinimumWageReader;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\Reading;
use App\Domain\TaxKnowledge\FiscalParameters\Recording\CandidateRecorder;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Reads every fiscal value there is to read, and proposes each one for a person to approve.
 *
 * 1. The minimum wage, from the Government\'s decisions already read in — every threshold counts in it.
 * 2. Every other value from the Codul fiscal itself, year by year ({@see LawValueReader}).
 * 3. The rest recorded as candidates — nothing counts until a person approves it.
 *
 * Only one runs at a time: the task started from the screen, and the one a newly read minimum-wage
 * decision starts on its own (`$rules` off, only the wages read again).
 */
final readonly class ReadFiscalParameters
{
    public function __construct(
        private ParameterCatalog $catalog,
        private MinimumWageReader $minimumWages,
        private LawValueReader $lawValues,
        private CandidateRecorder $recorder,
        private ConfigRepository $config,
    ) {
    }

    public function __invoke(bool $rules = true): ReadFiscalParametersResult
    {
        $result = Cache::lock('fiscal-parameters:read', $this->config->integer('fiscal_parameters.lock_seconds'))
            ->block($this->config->integer('fiscal_parameters.lock_seconds'), fn (): ReadFiscalParametersResult => $this->read($rules));

        return $result instanceof ReadFiscalParametersResult ? $result : $this->read($rules);
    }

    private function read(bool $rules): ReadFiscalParametersResult
    {
        $wageReadings = $this->minimumWages->read();
        $found = array_map(static fn (Reading $reading): array => ['reading' => $reading, 'matches' => []], $wageReadings);
        $fromLaw = $rules ? $this->lawValues->read() : [];

        $replacing = $rules
            ? array_map(static fn (ParameterDefinition $definition): string => $definition->code, $this->catalog->all())
            : [$this->catalog->minimumWage()->code];

        return new ReadFiscalParametersResult(
            minimumWages: count($wageReadings),
            candidates: $this->recorder->record([...$found, ...$fromLaw], $replacing),
        );
    }
}
