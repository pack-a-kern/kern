<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FiscalParameters;

use App\Domain\TaxKnowledge\FiscalParameters\Jobs\ReadFiscalYear;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\Services\AuditChainService;

/**
 * `POST /api/v1/fiscal-parameters/years` — read one year's law, for every value it states.
 *
 * Queued, and answered at once: reading a year is a model call per value, minutes rather than the
 * seconds a request may take. What it proposes appears on the screen as it is read.
 */
final class StartFiscalYearController extends Controller
{
    use RecordsFiscalParameterDecision;

    public function __invoke(Request $request, AuditChainService $audit, ConfigRepository $config): JsonResponse
    {
        /** @var array{year: int} $input */
        $input = $request->validate([
            'year' => ['required', 'integer', 'min:' . $config->integer('ai.legislation.from_year'), 'max:' . ((int) date('Y') + 1)],
        ]);
        $userId = $this->decidedBy($request);
        $year = (int) $input['year'];

        ReadFiscalYear::dispatch($year);
        $this->audit($audit, $userId, 'FISCAL_YEAR_STARTED', 'fiscal_year', (string) $year, ['year' => $year]);

        return response()->json(['year' => $year], 202);
    }
}
