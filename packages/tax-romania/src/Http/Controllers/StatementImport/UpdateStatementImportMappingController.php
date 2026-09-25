<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Http\Controllers\StatementImport\Concerns\ValidatesStatementForm;
use Kern\TaxRomania\StatementImport\Actions\ApplyColumnMapping;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementForm;
use Kern\TaxRomania\StatementImport\Enums\MappingSource;
use Kern\TaxRomania\StatementImport\Exceptions\CurrencyRequiredException;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\StatementImport\Exceptions\MappingNotUsableException;
use Kern\TaxRomania\StatementImport\Exceptions\StatementParseUnavailableException;
use Kern\TaxRomania\StatementImport\Exceptions\StatementRejectedException;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * `PUT .../wallet/imports/{import}/mapping` — how the file is built, confirmed by a person.
 *
 * Applying it reads the whole file and builds the transactions to review, so this is where a form
 * that does not actually read the statement is refused — with both counts, so the screen can say
 * "reads 812 of 940" and the person can fix the field rather than import a fragment.
 */
final class UpdateStatementImportMappingController
{
    use ValidatesStatementForm;

    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        ApplyColumnMapping $apply,
    ): JsonResponse {
        $model = StatementImport::query()->findOrFail($import);

        $validated = validator($this->asChains($request), [
            ...$this->formRules(),
            // Asked here rather than at upload: by now the file has been read, and the bank found
            // from its IBAN — or suggested — is already in the field for the person to confirm.
            // A closed bank too: a statement from before a merger was issued by the bank that existed then.
            'bank_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('ext_banks', 'id')],
        ])->validate();

        try {
            if ($request->has('bank_id')) {
                $model->chooseBank(is_string($validated['bank_id'] ?? null) ? $validated['bank_id'] : null);
            }

            $apply(
                $model,
                StatementForm::fromArray($validated),
                MappingSource::tryFrom(is_string($validated['source'] ?? null) ? $validated['source'] : '')
                    ?? MappingSource::PERSON,
                $this->actorId($request),
            );
        } catch (MappingNotUsableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'MAPPING_NOT_USABLE',
                'parsed' => $exception->parsed,
                'total' => $exception->total,
            ], 422);
        } catch (CurrencyRequiredException $exception) {
            // Answerable on the form that is still open: the currency field takes a plain code.
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'CURRENCY_REQUIRED',
            ], 422);
        } catch (ImportNotOpenException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'IMPORT_NOT_OPEN',
            ], 409);
        } catch (StatementRejectedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
            ], 422);
        } catch (StatementParseUnavailableException $exception) {
            // The reader, not the file: nothing the person can fix, and the import is unchanged.
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'READER_UNAVAILABLE',
            ], 503);
        }

        return response()->json(['id' => $model->id, 'status' => $model->status->value]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }

}
