<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\FiscalParameters;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\FiscalParameters\Actions\DropFiscalParameterValues;

/**
 * `DELETE /api/v1/fiscal-parameters/values` — every value read, proposed or decided, dropped for
 * reading again. Recorded in the audit chain with how many went, as every decision on these is.
 */
final class DropFiscalParameterValuesController
{
    use RecordsFiscalParameterDecision;

    public function __invoke(Request $request, DropFiscalParameterValues $drop, AuditChainService $audit): JsonResponse
    {
        $userId = $this->decidedBy($request);
        $dropped = $drop();

        $this->audit($audit, $userId, 'FISCAL_PARAMETERS_DROPPED', 'fiscal_parameters', null, $dropped);

        return response()->json(['versions_dropped' => $dropped['versions'], 'reads_dropped' => $dropped['reads']]);
    }
}
