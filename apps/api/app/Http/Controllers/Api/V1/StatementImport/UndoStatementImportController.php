<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\StatementImport;

use App\Domain\StatementImport\Actions\UndoStatementImport;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST .../wallet/imports/{import}/undo` — take a whole import back out of the ledger.
 *
 * Synchronous, unlike the commit: deleting is fast, and a person undoing an import wants to see the
 * transactions gone rather than a job they have to trust.
 */
final class UndoStatementImportController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        UndoStatementImport $undo,
    ): JsonResponse {
        $model = StatementImport::query()->findOrFail($import);

        try {
            $undo($model, $this->actorId($request));
        } catch (ImportNotOpenException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'IMPORT_NOT_OPEN',
            ], 409);
        }

        return response()->json(['id' => $model->id, 'status' => $model->status->value]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
