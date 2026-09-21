<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\StatementImport;

use App\Domain\StatementImport\Actions\RequestColumnSuggestion;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `POST .../wallet/imports/{import}/mapping/suggest` — read the file again and ask how it is built.
 *
 * Accepted rather than answered: the file is read and the model asked on the model queue, and the
 * screen follows the import back through READING to the form.
 */
final class RequestColumnSuggestionController extends Controller
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
