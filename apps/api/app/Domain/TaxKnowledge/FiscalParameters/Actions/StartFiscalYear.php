<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Actions;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionException;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawMatcher;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\Reading;
use App\Domain\TaxKnowledge\FiscalParameters\Recording\CandidateRecorder;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use DateTimeImmutable;

/**
 * A year no form has been published for yet — 2026, whose D212 is ANAF's web form only — started
 * from the year before: every value approved for it proposed again, if the law in force on 1 January
 * still says it.
 *
 * Proposed, never approved: the new year's law is searched afresh, and a person approves each value
 * or changes what changed. The minimum wage is not carried — its decisions are its source, and a new
 * one is read in from the legislation page.
 */
final readonly class StartFiscalYear
{
    public function __construct(
        private ParameterCatalog $catalog,
        private LawMatcher $law,
        private CandidateRecorder $recorder,
    ) {
    }

    /** @return int the candidates proposed for the year */
    public function __invoke(int $year): int
    {
        $from = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $until = new DateTimeImmutable(sprintf('%04d-12-31', $year));
        $lastDay = $from->modify('-1 day')->format('Y-m-d');
        $found = [];

        foreach ($this->catalog->all() as $definition) {
            if (! $definition->isInLaw()) {
                continue;
            }

            $approved = FiscalParameterVersion::query()
                ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', $definition->code)->select('id'))
                ->where('status', RuleApprovalStatus::APPROVED->value)
                ->where('valid_from', '<=', $lastDay)
                ->where(static function ($query) use ($lastDay): void {
                    $query->whereNull('valid_until')->orWhere('valid_until', '>=', $lastDay);
                })
                ->first();

            if ($approved === null) {
                continue;
            }

            $numbers = $definition->lawValues($approved->value_json);
            $matches = $this->law->find($definition, $numbers, $from, $until);

            if ($matches !== []) {
                $found[] = [
                    'reading' => new Reading($definition->code, $from, $until, $approved->value_json, $numbers, [
                        'kind' => 'carried',
                        'from_version_id' => $approved->id,
                        'from_year' => $year - 1,
                    ]),
                    'matches' => $matches,
                ];
            }
        }

        if ($found === []) {
            throw FiscalParameterDecisionException::nothingToCarry($year - 1);
        }

        return $this->recorder->record($found, []);
    }
}
