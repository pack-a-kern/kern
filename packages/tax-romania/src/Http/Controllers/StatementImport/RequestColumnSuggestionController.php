<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\StatementImport\Actions\RequestColumnSuggestion;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * `POST .../wallet/imports/{import}/mapping/suggest` — read the file again and ask how it is built.
 *
 * Accepted rather than answered: the file is read and the model asked on the model queue, and the
 * screen follows the import back through READING to the form.
 */
final class RequestColumnSuggestionController
{
    public function __invoke(string $subject, string $import, RequestColumnSuggestion $suggest): JsonResponse
    {
        $model = StatementImport::query()->findOrFail($import);

        try {
            $suggest($model);
        } catch (ImportNotOpenException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'IMPORT_NOT_OPEN'], 409);
        }

        return response()->json(['id' => $model->id, 'status' => $model->status->value], 202);
    }
}
