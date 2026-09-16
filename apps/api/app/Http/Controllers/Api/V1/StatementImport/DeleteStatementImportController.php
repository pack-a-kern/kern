<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\StatementImport;

use App\Domain\StatementImport\Actions\DeleteStatementImport;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `DELETE .../wallet/imports/{import}` — drop an import that never became transactions.
 *
 * Refused for one that did: those are undone first, so the ledger never holds figures pointing at an
 * import nobody can look up.
 */
final class DeleteStatementImportController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        DeleteStatementImport $delete,
    ): JsonResponse|Response {
        // Tenant-scoped: another subject's import is not found rather than deleted.
        $model = StatementImport::query()->findOrFail($import);

        try {
            $delete($model, $this->actorId($request));
        } catch (ImportNotOpenException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'IMPORT_HOLDS_TRANSACTIONS',
            ], 409);
        }

        return response()->noContent();
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
