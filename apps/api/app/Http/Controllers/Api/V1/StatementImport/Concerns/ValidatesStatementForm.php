<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\StatementImport\Concerns;

use App\Domain\StatementImport\DataTransferObjects\StatementField;
use App\Domain\StatementImport\DataTransferObjects\StatementForm;
use Illuminate\Validation\Rule;

/**
 * The shape of a statement form, as two endpoints must agree on it.
 *
 * Confirming a form and previewing one are the same contract answered twice: the preview shows what
 * the confirmation will do, so a rule that held in one and not the other would make the preview a
 * lie. Shared because it is one contract, not because the code looked similar.
 */
trait ValidatesStatementForm
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function formRules(): array
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
