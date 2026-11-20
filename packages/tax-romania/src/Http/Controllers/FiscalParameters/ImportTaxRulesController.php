<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\FiscalParameters;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\TaxEngine\Registration\TaxRuleRegistrar;

/**
 * `POST /api/v1/fiscal-parameters/rules/import` — record a candidate version for every rule class the
 * database is missing, and return the new sync figures.
 *
 * The screen's Import button, doing exactly what `kern:tax:register-rules` does: brings the table
 * level with the code as `CANDIDATE` versions, never approving one (approval stays the separate,
 * named act). Gated by `fiscal.parameters.approve` on the route — only a super administrator may
 * change what the installation computes — the same bar the command's `--approve-as` and the
 * parameter-approval screen hold.
 */
final class ImportTaxRulesController
{
    public function __invoke(TaxRuleRegistrar $registrar): JsonResponse
    {
        $recorded = $registrar->registerMissing();

        return response()->json(['recorded' => $recorded, ...$registrar->status()]);
    }
}
