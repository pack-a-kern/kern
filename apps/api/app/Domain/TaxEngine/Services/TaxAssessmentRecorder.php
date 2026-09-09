<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Services;

use App\Domain\TaxEngine\DataTransferObjects\TaxAssessmentResult;
use App\Domain\TaxEngine\Models\TaxAssessment;
use App\Domain\TaxEngine\Models\TaxAssessmentLine;
use Illuminate\Support\Facades\DB;

/**
 * Persists a computed `TaxAssessmentResult` (`App\Domain\TaxEngine\Services\TaxAssessor::assess()`)
 * as one `tax_assessments` row plus its `tax_assessment_lines`. Deliberately separate from
 * `TaxAssessor` itself: assessing is a pure computation over already-fetched inputs, recording is
 * the one place this module writes to its own tables — keeping the split makes it possible to
 * compute (and show a user) a `DRAFT` result without ever persisting it, which
 * `App\Domain\TaxEngine\Enums\AssessmentStatus::DRAFT`'s whole purpose depends on.
 *
 * `totals_json` stores each RON total as a decimal string (`Money::toString()` without the
 * currency suffix would still be ambiguous about minor-unit scale to a future reader; storing
 * `{"tax_code": {"minor_units": N, "currency": "RON"}}` keeps it unambiguous and round-trippable
 * without re-deriving anything).
 *
 * `App\Domain\TaxEngine\Models\TaxAssessment::calculation_trace_object_path` is left null — see
 * that migration's docblock for why; the full trace is still persisted per-line via
 * `calculation_trace_json`.
 */
final readonly class TaxAssessmentRecorder
{
    public function record(TaxAssessmentResult $result): TaxAssessment
    {
        return DB::transaction(function () use ($result): TaxAssessment {
            $totals = [];
            foreach ($result->totals as $taxCode => $amount) {
                $totals[$taxCode] = [
                    'minor_units' => $amount->minorUnits,
                    'currency' => $amount->currencyCode,
                ];
            }

            $assessment = TaxAssessment::create([
                'tax_subject_id' => $result->taxSubjectId,
                'fiscal_year' => $result->fiscalYear,
                'as_of' => $result->asOf->format('Y-m-d'),
                'ruleset_id' => $result->rulesetId,
                'input_snapshot_hash' => $result->inputSnapshotHash,
                'status' => $result->status,
                'totals_json' => $totals,
            ]);

            foreach ($result->lines as $line) {
                TaxAssessmentLine::create([
                    'assessment_id' => $assessment->id,
                    'tax_code' => $line->taxCode,
                    'category_code' => $line->categoryCode,
                    'taxable_base_minor' => $line->taxableBase->minorUnits,
                    'rate_decimal' => $line->rate?->toString(),
                    'tax_minor' => $line->tax->minorUnits,
                    'legal_rule_version_id' => $line->legalRuleVersionId,
                    'explanation_key' => $line->explanationKey,
                    'calculation_trace_json' => $line->trace->toArray(),
                ]);
            }

            return $assessment;
        });
    }
}
