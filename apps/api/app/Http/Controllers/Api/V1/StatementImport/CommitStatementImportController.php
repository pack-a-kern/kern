<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\StatementImport;

use App\Domain\StatementImport\Actions\CommitStatementImport;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST .../wallet/imports/{import}/commit` — write the ticked rows into the ledger.
 *
 * 202, like the upload: several hundred transactions with their splits and tags are written by a
 * job, and the screen follows the import's status rather than waiting on a request.
 */
final class CommitStatementImportController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        CommitStatementImport $commit,
    ): JsonResponse {
        $model = StatementImport::query()->findOrFail($import);

        try {
            $commit($model, $this->actorId($request));
        } catch (ImportNotOpenException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'IMPORT_NOT_OPEN',
            ], 409);
        }

        return response()->json(['id' => $model->id, 'status' => $model->status->value], 202);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
