<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kern\TaxRomania\StatementImport\Actions\DeleteStatementImport;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * `DELETE .../wallet/imports/{import}` — drop an import that never became transactions.
 *
 * Refused for one that did: those are undone first, so the ledger never holds figures pointing at an
 * import nobody can look up.
 */
final class DeleteStatementImportController
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
