<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Services\TagDirectory;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/subjects/{subject}/wallet/tags` — this taxpayer's own labels for their spending.
 *
 * Decrypted here, for the same reason a filename is on the documents list: encryption at rest
 * protects a stolen backup, never a person from their own data. Every row is tenant-scoped.
 */
final class ShowTagsController
{
    public function __invoke(TagDirectory $tags): JsonResponse
    {
        $rows = [];

        foreach ($tags->all() as $id => $name) {
            $rows[] = ['id' => $id, 'name' => $name];
        }

        return response()->json(['tags' => $rows]);
    }
}
