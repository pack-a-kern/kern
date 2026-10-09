<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\TaxEngine;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Http\Presenters\TaxEngine\AssessmentPresenter;
use Kern\TaxRomania\TaxEngine\Contracts\LedgerIncomeProvider;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * `GET .../tax/assessments?fiscal_year=…` — the assessment already on file for a year.
 *
 * The assessment is saved, so a person returning to the screen should find it rather than press a button to
 * recompute what is already recorded. Without this the page was blank on every visit and the only way to see
 * last week's figures was to produce new ones — which also replaced them, so the old draft could not be
 * compared against the new even in principle.
 *
 * `FINAL` first, then the draft. A filing rests on a final assessment, so where both exist the final one is
 * what the year *is*; the draft beside it is working toward the next version.
 *
 * A year never assessed is `{"assessment": null}` rather than a 404. Nothing is missing — it has not been done
 * yet, and a screen reading that as an error shows a failure where it should show a button. It is a 200 because
 * the response still carries the years the picker offers, and a 204 has no body to carry them in.
 */
final class ShowFiscalYearAssessmentController
{
    public function __invoke(
        Request $request,
        string $subject,
        AssessmentPresenter $presenter,
        LedgerIncomeProvider $ledger,
    ): JsonResponse
    {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2018', 'max:2100'],
        ]);

        $assessment = TaxAssessment::query()
            ->where('tax_subject_id', $subject)
            ->where('fiscal_year', (int) $data['fiscal_year'])
            // `FINAL` sorts after `DRAFT` alphabetically, so the order is stated rather than relied upon.
            ->orderByRaw("case when status = ? then 0 else 1 end", [AssessmentStatus::FINAL->value])
            ->orderByDesc('created_at')
            ->first();

        /*
         * The years worth offering travel with the answer, because the screen needs both on the same load and
         * a second request for a list of integers is a second chance to be out of step with the first.
         */
        $years = $ledger->yearsWithActivity($subject);

        if (! $assessment instanceof TaxAssessment) {
            // 204 carries no body, so a year never assessed still has to hand the picker its options.
            return response()->json(['assessment' => null, 'years' => $years]);
        }

        return response()->json(['assessment' => $presenter->present($assessment), 'years' => $years]);
    }
}
