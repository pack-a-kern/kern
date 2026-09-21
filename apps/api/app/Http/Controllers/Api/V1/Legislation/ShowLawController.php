<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Legislation;

use App\Domain\TaxKnowledge\Queries\LegislationLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** `GET /api/v1/legislation/laws/{law}` — one law and every consolidation the portal lists, newest first. */
final class ShowLawController extends Controller
{
    public function __invoke(string $law, LegislationLibrary $library): JsonResponse
    {
        $found = $library->law($law);

        abort_if($found === null, 404);

        return response()->json(['law' => $found]);
    }
}
