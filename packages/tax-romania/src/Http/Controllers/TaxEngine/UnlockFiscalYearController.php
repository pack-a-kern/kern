<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\TaxEngine;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Http\Presenters\TaxEngine\AssessmentPresenter;
use Kern\TaxRomania\TaxEngine\Actions\UnlockFiscalYear;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * `POST .../tax/assessments/unlock` — turn a final year back into a draft.
 *
 * A 404 when the year has no final assessment, rather than succeeding quietly: "unlock" on a year that was
 * never locked is a request made against a state the caller thinks exists, and answering 200 lets a screen
 * carry on believing something it got wrong.
 */
final class UnlockFiscalYearController
{
    public function __invoke(
        Request $request,
        string $subject,
        UnlockFiscalYear $unlock,
        AssessmentPresenter $presenter,
    ): JsonResponse {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2018', 'max:2100'],
        ]);

        $assessment = TaxAssessment::query()
            ->where('tax_subject_id', $subject)
            ->where('fiscal_year', (int) $data['fiscal_year'])
            ->where('status', AssessmentStatus::FINAL->value)
            ->firstOrFail();

        return response()->json($presenter->present($unlock($assessment)));
    }
}
