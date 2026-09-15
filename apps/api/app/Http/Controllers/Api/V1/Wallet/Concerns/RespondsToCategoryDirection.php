<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet\Concerns;

use App\Domain\Wallet\Exceptions\CategoryDirectionException;
use Illuminate\Http\JsonResponse;

/**
 * The one answer to a category given to money moving the wrong way, for every endpoint that records
 * a category — typed on the transactions screen, answered from the worklist, or proposed by a document
 * reading.
 */
trait RespondsToCategoryDirection
{
    /**
     * 422 with a reason the screen translates. The forms only offer categories that fit, so this is
     * reached by a form left open across a change, or by a document whose reading proposed one.
     */
    private function categoryDirection(CategoryDirectionException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'reason' => 'CATEGORY_DIRECTION',
        ], 422);
    }
}
