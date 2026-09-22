<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FiscalParameters;

use App\Domain\Audit\Services\AuditChainService;
use App\Domain\TaxKnowledge\FiscalParameters\Actions\StartFiscalYear;
use App\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/fiscal-parameters/years` — a year no form states yet, proposed from the year before:
 * every value approved for it that the new year's law still says, to approve again or change.
 */
final class StartFiscalYearController extends Controller
{
    use RecordsFiscalParameterDecision;

    public function __invoke(Request $request, StartFiscalYear $start, AuditChainService $audit, ConfigRepository $config): JsonResponse
    {
        /** @var array{year: int} $input */
        $input = $request->validate([
            'year' => ['required', 'integer', 'min:' . ($config->integer('legislation.form.min_year') + 1), 'max:' . ((int) date('Y') + 1)],
        ]);
        $userId = $this->decidedBy($request);

        try {
            $proposed = $start((int) $input['year']);
        } catch (FiscalParameterDecisionException $exception) {
            return $this->refused($exception);
        }

        $this->audit($audit, $userId, 'FISCAL_YEAR_STARTED', 'fiscal_year', (string) $input['year'], ['year' => (int) $input['year'], 'proposed' => $proposed]);

        return response()->json(['year' => (int) $input['year'], 'proposed' => $proposed], 201);
    }
}
