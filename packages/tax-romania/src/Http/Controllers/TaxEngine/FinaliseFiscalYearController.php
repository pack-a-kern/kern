<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\TaxEngine;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Http\Presenters\TaxEngine\AssessmentPresenter;
use Kern\TaxRomania\TaxEngine\Actions\FinaliseFiscalYear;

/**
 * `POST .../tax/assessments/final` — mark a fiscal year final.
 *
 * Behind `verify-recent` like the assessment it recomputes, and for a stronger reason: this one locks a year's
 * transactions against every path that could change them.
 */
final class FinaliseFiscalYearController
{
    public function __invoke(
        Request $request,
        string $subject,
        FinaliseFiscalYear $finalise,
        AssessmentPresenter $presenter,
    ): JsonResponse {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2018', 'max:2100'],
        ]);

        return response()->json($presenter->present($finalise($subject, (int) $data['fiscal_year'])), 201);
    }
}
