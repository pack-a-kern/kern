<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FiscalParameters;

use App\Domain\Audit\Services\AuditChainService;
use App\Domain\TaxKnowledge\FiscalParameters\Actions\RejectFiscalParameterVersion;
use App\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /api/v1/fiscal-parameters/versions/{version}/reject` — a person rejects a proposed value. */
final class RejectFiscalParameterVersionController extends Controller
{
    use RecordsFiscalParameterDecision;

    public function __invoke(Request $request, string $version, RejectFiscalParameterVersion $reject, AuditChainService $audit): JsonResponse
    {
        $userId = $this->decidedBy($request);

        try {
            $rejected = $reject($version);
        } catch (FiscalParameterDecisionException $exception) {
            return $this->refused($exception);
        }

        $this->audit($audit, $userId, 'FISCAL_PARAMETER_REJECTED', 'fiscal_parameter_version', $rejected->id, [
            'value' => $rejected->value_json,
            'valid_from' => $rejected->valid_from->format('Y-m-d'),
        ]);

        return response()->json(['id' => $rejected->id, 'status' => $rejected->status->value]);
    }
}
