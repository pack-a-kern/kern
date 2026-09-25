<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FiscalParameters;

use App\Domain\FiscalParameters\Actions\ApproveFiscalParameterVersion;
use App\Domain\FiscalParameters\FiscalParameterDecisionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\Services\AuditChainService;

/**
 * `POST /api/v1/fiscal-parameters/versions/{version}/approve` — a person approves a proposed value,
 * on the paragraph of the law they chose among those found (`section_id`).
 */
final class ApproveFiscalParameterVersionController
{
    use RecordsFiscalParameterDecision;

    public function __invoke(Request $request, string $version, ApproveFiscalParameterVersion $approve, AuditChainService $audit): JsonResponse
    {
        /** @var array{section_id: string} $input */
        $input = $request->validate(['section_id' => ['required', 'uuid']]);
        $userId = $this->decidedBy($request);

        try {
            $approved = $approve($version, $input['section_id'], $userId);
        } catch (FiscalParameterDecisionException $exception) {
            return $this->refused($exception);
        }

        $this->audit($audit, $userId, 'FISCAL_PARAMETER_APPROVED', 'fiscal_parameter_version', $approved->id, [
            'value' => $approved->value_json,
            'valid_from' => $approved->valid_from->format('Y-m-d'),
            'source_section_id' => $approved->source_section_id,
        ]);

        return response()->json(['id' => $approved->id, 'status' => $approved->status->value]);
    }
}
