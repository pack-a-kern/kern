<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Actions;

use App\Domain\FiscalParameters\ParameterCatalog;
use App\Domain\FiscalParameters\Reading\MinimumWageReader;
use App\Domain\FiscalParameters\Reading\Reading;
use App\Domain\FiscalParameters\Recording\CandidateRecorder;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;

/**
 * The minimum wage as the Government's decisions set it, proposed for a person to approve.
 *
 * Every threshold in the law counts in this wage, and it is the one value no reading of the law
 * gives: its source is a decision of its own, read in with the rest of the legislation. It is read
 * again whenever a new decision arrives ({@see \App\Domain\FiscalParameters\Jobs\ReadMinimumWages}).
 *
 * Everything else is read from the Codul fiscal, one year at a time
 * ({@see \App\Domain\FiscalParameters\Actions\StartFiscalYear}).
 *
 * Only one reading runs at a time, so the same value is not proposed twice.
 */
final readonly class ReadFiscalParameters
{
    public function __construct(
        private ParameterCatalog $catalog,
        private MinimumWageReader $minimumWages,
        private CandidateRecorder $recorder,
        private ConfigRepository $config,
    ) {
    }

    public function __invoke(): ReadFiscalParametersResult
    {
        $result = Cache::lock('fiscal-parameters:read', $this->config->integer('fiscal_parameters.lock_seconds'))
            ->block($this->config->integer('fiscal_parameters.lock_seconds'), fn (): ReadFiscalParametersResult => $this->read());

        return $result instanceof ReadFiscalParametersResult ? $result : $this->read();
    }

    private function read(): ReadFiscalParametersResult
    {
        $readings = $this->minimumWages->read();

        return new ReadFiscalParametersResult(
            minimumWages: count($readings),
            candidates: $this->recorder->record(
                array_map(static fn (Reading $reading): array => ['reading' => $reading, 'matches' => []], $readings),
                [$this->catalog->minimumWage()->code],
            ),
        );
    }
}
