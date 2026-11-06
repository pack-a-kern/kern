<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\FiscalParameters;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\FiscalParameters\Queries\FilingDeadline;

/**
 * `GET /api/v1/fiscal-parameters/filing-deadline` — the next day a Declarația unică is due, or null
 * where no approved value says it. The law's, not a taxpayer's: the same day for everyone.
 */
final class ShowFilingDeadlineController
{
    public function __invoke(FilingDeadline $deadline): JsonResponse
    {
        return response()->json(['deadline' => $deadline->next()]);
    }
}
