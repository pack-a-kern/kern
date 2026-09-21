<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Legislation;

use App\Domain\TaxKnowledge\Queries\LegislationLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** `GET /api/v1/legislation/laws` — every law read, with what the portal says about it. */
final class ListLawsController extends Controller
{
    public function __invoke(LegislationLibrary $library): JsonResponse
    {
        return response()->json(['laws' => $library->laws()]);
    }
}
