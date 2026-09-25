<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\StatementImport\Actions\RequestRowSuggestions;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * `POST .../wallet/imports/{import}/suggestions` — ask the model again about the unanswered rows.
 *
 * Accepted rather than answered: the calls run on the model queue, and the screen follows them over
 * the import's channel as they finish.
 */
final class RequestRowSuggestionsController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        RequestRowSuggestions $suggest,
    ): JsonResponse {
        $model = StatementImport::query()->findOrFail($import);
        $actor = $request->user()?->getAuthIdentifier();

        try {
            $suggest($model, is_scalar($actor) ? (string) $actor : null);
        } catch (ImportNotOpenException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'IMPORT_NOT_OPEN'], 409);
        }

        return response()->json(['id' => $model->id, 'status' => $model->status->value], 202);
    }
}
