<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementField;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementForm;

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
     * The request's form, with every chainable field written as a list of places.
     *
     * The wire keeps accepting one place as an object, because that is what is stored today and what
     * the model proposes; everything after this point sees one shape.
     *
     * @return array<string, mixed>
     */
    protected function asChains(Request $request): array
    {
        $form = $request->all();

        foreach (['counterparty', 'reference'] as $field) {
            if (! self::chainable($field) || ! is_array($form[$field] ?? null)) {
                continue;
            }

            foreach (['debit', 'credit'] as $side) {
                $place = $form[$field][$side] ?? null;

                if (is_array($place) && ! array_is_list($place)) {
                    $form[$field][$side] = [$place];
                }
            }
        }

        // `description` has no sides — it is one chain, not one per direction — so it is wrapped
        // here rather than in the loop above.
        if (self::chainable('description') && is_array($form['description'] ?? null) && ! array_is_list($form['description'])) {
            $form['description'] = [$form['description']];
        }

        return $form;
    }

    /** Whether this field of the form may name several places. */
    protected static function chainable(string $field): bool
    {
        /** @var list<string> $fields */
        $fields = config()->array('statement_import.multi_source_fields');

        return in_array($field, $fields, true);
    }

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
        $field = static function (string $key, bool $columnsOnly = false, bool $chain = false): array {
            $kinds = $columnsOnly
                ? [StatementField::INDEX]
                : [StatementField::INDEX, StatementField::LABEL, StatementField::CODE];

            /*
             * A field that may name several places is a list by the time it gets here.
             *
             * One place still arrives as an object — that is how every mapping saved so far is
             * written, and how the model proposes one — and `asChains()` wraps it before the rules
             * run. Describing both shapes here is not possible: `field.*` matches an object's own
             * keys as readily as a list's items, so a single place would be asked to have a `type`
             * that is itself an array.
             */
            if ($chain) {
                return [
                    $key => ['sometimes', 'nullable', 'array', 'max:' . config()->integer('statement_import.max_source_places')],
                    $key . '.*' => ['array'],
                    $key . '.*.type' => ['required', Rule::in($kinds)],
                    $key . '.*.value' => ['required', 'max:120'],
                ];
            }

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
            $field('description', chain: self::chainable('description')),
            // Which of these may name more than one place is `statement_import.multi_source_fields`,
            // so this layer, the form and the screen cannot disagree about it.
            $field('counterparty.debit', chain: self::chainable('counterparty')),
            $field('counterparty.credit', chain: self::chainable('counterparty')),
            $field('reference.debit', chain: self::chainable('reference')),
            $field('reference.credit', chain: self::chainable('reference')),
        );
    }
}
