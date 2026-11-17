<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\FiscalParameters;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\FiscalParameters\Jobs\ReadMinimumWages;

/**
 * `POST /api/v1/fiscal-parameters/minimum-wage` — read the minimum wage from the Government's
 * decisions already in the library, proposing a value for each, for a person to approve.
 *
 * The value normally proposes itself as a side-effect of a decision being freshly read from the
 * portal ({@see \Kern\TaxRomania\FiscalParameters\Listeners\ReadWagesFromANewLaw}). That never fires
 * for decisions already read — so when the values are gone but the laws remain (every value dropped,
 * a re-install against a populated library), nothing re-proposes them. This reads them from the laws
 * that are already there, the same reading the listener runs ({@see ReadMinimumWages}), on demand.
 *
 * Queued, and answered at once, like {@see StartFiscalYearController}: the reading is a search over
 * every decision. What it proposes appears on the screen as it is read.
 */
final class ReadMinimumWagesController
{
    use RecordsFiscalParameterDecision;

    public function __invoke(Request $request, AuditChainService $audit): JsonResponse
    {
        $userId = $this->decidedBy($request);

        ReadMinimumWages::dispatch();
        $this->audit($audit, $userId, 'MINIMUM_WAGE_READ_REQUESTED', 'fiscal_parameter', null, []);

        return response()->json(['queued' => true], 202);
    }
}
