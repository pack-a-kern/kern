<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Actions;

use App\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionException;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\LawValueReader;
use App\Domain\TaxKnowledge\FiscalParameters\Recording\CandidateRecorder;

/**
 * One year read on its own: the law in force in it read for every value, and each proposed.
 *
 * A year can be started before anything else says what it holds — 2026's values are in the law from
 * the day it is published, while ANAF's declaration for it appears in 2027. Each value is proposed
 * as it is read, so the screen fills while the year is still being read, and never approved here: a
 * person approves each value or leaves it.
 *
 * The minimum wage is not read here — its decisions are its source, and a new one is read in from
 * the legislation page.
 */
final readonly class StartFiscalYear
{
    public function __construct(
        private LawValueReader $lawValues,
        private CandidateRecorder $recorder,
    ) {
    }

    /** @return int the candidates proposed for the year */
    public function __invoke(int $year): int
    {
        $proposed = 0;

        // One value at a time, as the law is read for it: a year takes a model several minutes, and
        // a value read is kept whether or not the rest of the year is.
        foreach ($this->lawValues->read($year) as $value) {
            $proposed += $this->recorder->record([$value], []);
        }

        if ($proposed === 0) {
            throw FiscalParameterDecisionException::nothingToCarry($year);
        }

        return $proposed;
    }
}
