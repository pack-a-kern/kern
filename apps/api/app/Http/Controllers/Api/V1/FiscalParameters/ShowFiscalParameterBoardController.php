<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FiscalParameters;

use App\Domain\FiscalParameters\Queries\FiscalParameterBoard;
use Illuminate\Http\JsonResponse;

/** `GET /api/v1/fiscal-parameters` — every fiscal value, proposed or decided, with where it was read and where the law says it. */
final class ShowFiscalParameterBoardController
{
    public function __invoke(FiscalParameterBoard $board): JsonResponse
    {
        return response()->json($board());
    }
}
