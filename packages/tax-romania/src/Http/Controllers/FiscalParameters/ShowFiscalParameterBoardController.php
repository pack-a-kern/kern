<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\FiscalParameters;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\FiscalParameters\Queries\FiscalParameterBoard;

/** `GET /api/v1/fiscal-parameters` — every fiscal value, proposed or decided, with where it was read and where the law says it. */
final class ShowFiscalParameterBoardController
{
    public function __invoke(FiscalParameterBoard $board): JsonResponse
    {
        return response()->json($board());
    }
}
