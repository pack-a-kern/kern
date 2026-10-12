<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\TaxEngine;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Http\Presenters\TaxEngine\AssessmentPresenter;
use Kern\TaxRomania\TaxEngine\Actions\AssessFiscalYear;

/**
 * `POST .../tax/assessments` — compute a draft assessment for one fiscal year.
 *
 * The thing that was missing between an engine that could compute and a person who wanted a number. It
 * returns the lines it produced rather than an id, because a person pressing a button wants the answer and
 * the alternative is a second request to learn what just happened.
 *
 * Synchronous. Assessing a year reads that year's splits and runs a handful of pure functions over them —
 * work measured in milliseconds, not something to queue and poll. If a taxpayer ever has enough
 * transactions for that to stop being true, the fix is a job and this controller's shape is what would
 * change.
 *
 * The year is validated as a year rather than taken as given: a request for 1200 or 9999 would resolve no
 * parameter version and fail deep inside the resolver, where the message is about rule resolution rather
 * than about a typo.
 */
final class AssessFiscalYearController
{
    public function __invoke(
        Request $request,
        string $subject,
        AssessFiscalYear $assess,
        AssessmentPresenter $presenter,
    ): JsonResponse {
        $data = $request->validate([
            // From the first year the fiscal dataset covers to the one after this, which is as far ahead as
            // a parameter version is ever approved.
            'fiscal_year' => ['required', 'integer', 'min:2018', 'max:2100'],
        ]);

        $assessment = $assess($subject, (int) $data['fiscal_year']);

        return response()->json($presenter->present($assessment), 201);
    }




}
