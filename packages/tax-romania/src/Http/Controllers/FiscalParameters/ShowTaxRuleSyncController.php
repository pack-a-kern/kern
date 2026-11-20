<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\FiscalParameters;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\TaxEngine\Registration\TaxRuleRegistrar;

/**
 * `GET /api/v1/fiscal-parameters/rules/sync` — are the engine's rule classes recorded in the database?
 *
 * Reads only: how many rules the code defines, how many of those are registered, and which are not.
 * The screen's sync panel shows it so an operator can tell at a glance whether a release added rules
 * that still need importing. Gated by `fiscal.parameters.view` on the route (same as reading the
 * board) — the figures reveal nothing about any taxpayer.
 */
final class ShowTaxRuleSyncController
{
    public function __invoke(TaxRuleRegistrar $registrar): JsonResponse
    {
        return response()->json($registrar->status());
    }
}
