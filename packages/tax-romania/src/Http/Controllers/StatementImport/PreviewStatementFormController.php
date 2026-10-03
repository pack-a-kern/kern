<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Http\Controllers\StatementImport\Concerns\ValidatesStatementForm;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementForm;
use Kern\TaxRomania\StatementImport\Exceptions\MappingNotUsableException;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Services\StatementRowBuilder;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

/**
 * `POST .../wallet/imports/{import}/mapping/preview` — what this form would make of the first lines.
 *
 * ## Why the server answers this
 *
 * Because the alternative is a second implementation. Turning lines into transactions is folding
 * detail lines into the line above, reading a date written in words, choosing a label by the
 * direction of the money — and a copy of that in the browser would drift from the one that does the
 * real import, which is precisely the case where a preview is worse than none: it would show rows
 * that will not be what gets saved.
 *
 * So the same {@see StatementRowBuilder} answers both, over the sample rows already stored for the
 * screen. No file is read, nothing is staged, nothing is written.
 *
 * ## It never refuses
 *
 * A half-filled form is the normal state of a form someone is still filling in. A form that reads
 * nothing yet comes back as no transactions rather than an error, because "this does not work yet"
 * is what the empty preview is already saying.
 */
final class PreviewStatementFormController
{
    use ValidatesStatementForm;

    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        StatementRowBuilder $builder,
    ): JsonResponse {
        $model = StatementImport::query()->findOrFail($import);
        $form = StatementForm::fromArray(validator($this->asChains($request), $this->formRules())->validate());

        $account = FinancialAccount::query()->find($model->account_id);
        $rows = $model->preview()['rows'];

        try {
            // No threshold: a preview judges nothing, it only shows.
            // No headers: this is the dry run behind the mapping screen, which shows what each row
            // would become and not the fields kept beside it. The import itself names them.
            $built = $builder->build($rows, $form, 0.0, $account?->currency_code);
        } catch (MappingNotUsableException) {
            return response()->json(['transactions' => [], 'lines_folded' => 0, 'lines_skipped' => 0]);
        }

        return response()->json([
            'transactions' => array_slice(
                $built->rows,
                0,
                max(1, config()->integer('statement_import.limits.preview_transactions')),
            ),
            'lines_folded' => $built->linesFolded,
            'lines_skipped' => $built->linesSkipped,
        ]);
    }
}
