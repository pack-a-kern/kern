<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\StatementImport;

use App\Domain\StatementImport\Actions\ApplyColumnMapping;
use App\Domain\StatementImport\DataTransferObjects\StatementField;
use App\Domain\StatementImport\DataTransferObjects\StatementForm;
use App\Domain\StatementImport\Enums\MappingSource;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Exceptions\MappingNotUsableException;
use App\Domain\StatementImport\Exceptions\StatementParseUnavailableException;
use App\Domain\StatementImport\Exceptions\StatementRejectedException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `PUT .../wallet/imports/{import}/mapping` — how the file is built, confirmed by a person.
 *
 * Applying it reads the whole file and builds the transactions to review, so this is where a form
 * that does not actually read the statement is refused — with both counts, so the screen can say
 * "reads 812 of 940" and the person can fix the field rather than import a fragment.
 */
final class UpdateStatementImportMappingController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        ApplyColumnMapping $apply,
    ): JsonResponse {
        $model = StatementImport::query()->findOrFail($import);

        $validated = $request->validate($this->rules());

        try {
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

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        /*
         * Every field is the same three-part shape — a kind, and a column index or a label — so the
         * rules are built once rather than written out eight times. Amount fields are columns only:
         * a figure written on a detail line as a label is not something this can add up.
         */
        $field = static function (string $key, bool $columnsOnly = false): array {
            $kinds = $columnsOnly
                ? [StatementField::INDEX]
                : [StatementField::INDEX, StatementField::LABEL, StatementField::CODE];

            return [
                $key => ['sometimes', 'nullable', 'array'],
                $key . '.type' => ['required_with:' . $key, Rule::in($kinds)],
                $key . '.value' => ['required_with:' . $key, 'max:120'],
            ];
        };

        $signed = 'amounts.mode,' . StatementForm::MODE_SIGNED;
        $split = 'amounts.mode,' . StatementForm::MODE_DEBIT_CREDIT;

        return array_merge(
            [
                'layout' => ['required', Rule::in([StatementForm::LAYOUT_ROW, StatementForm::LAYOUT_BLOCK])],

                'date' => ['required', 'array'],
                'date.value' => ['required', 'integer', 'min:0'],
                'date.format' => ['required', Rule::in(StatementForm::DATE_FORMATS)],

                'amounts' => ['required', 'array'],
                'amounts.mode' => ['required', Rule::in([StatementForm::MODE_SIGNED, StatementForm::MODE_DEBIT_CREDIT])],
                'amounts.amount' => ['nullable', 'array', 'required_if:' . $signed],
                // One of the two is enough: plenty of exports have a debit column and no credit one
                // in a month where nothing came in.
                'amounts.debit' => ['nullable', 'array', 'required_without_all:amounts.credit', 'exclude_if:' . $signed],
                'amounts.credit' => ['nullable', 'array', 'required_without_all:amounts.debit', 'exclude_if:' . $signed],
                'amounts.decimal_separator' => ['required', 'string', 'size:1'],
                'amounts.thousands_separator' => ['sometimes', 'nullable', 'string', 'max:1'],

                // A heading or footer to drop, and a label whose value is an identifier to never
                // read. Bounded: this is a form a person fills in, not a rule engine.
                'non_transaction_lines' => ['sometimes', 'array', 'max:20'],
                'non_transaction_lines.*' => ['string', 'max:120'],
                'identity_labels' => ['sometimes', 'array', 'max:20'],
                'identity_labels.*' => ['string', 'max:60'],

                // Which side proposed it — the screen says `AI` only when the person accepted a
                // suggestion unchanged.
                'source' => ['sometimes', Rule::enum(MappingSource::class)],
            ],
            $field('amounts.amount', columnsOnly: true),
            $field('amounts.debit', columnsOnly: true),
            $field('amounts.credit', columnsOnly: true),
            $field('currency'),
            $field('description'),
            $field('counterparty.debit'),
            $field('counterparty.credit'),
            $field('reference.debit'),
            $field('reference.credit'),
        );
    }
}
