<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Domain\StatementImport\DataTransferObjects\AiAnswer;
use App\Domain\StatementImport\DataTransferObjects\FormSuggestion;
use App\Domain\StatementImport\DataTransferObjects\ParsedStatementFile;
use App\Domain\StatementImport\DataTransferObjects\StatementField;
use App\Domain\StatementImport\DataTransferObjects\StatementForm;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Asks the model how the file is built, and checks the answer against the file before believing it.
 *
 * ## What is sent
 *
 * Three things: the columns with their headers and shapes, the `Label:` prefixes grouped by which
 * amount column their transactions used, and the first lines of the file as text. The first two are
 * structure and carry nobody's payments. The sample lines are the file itself — sent because a model
 * cannot tell a detail line from a heading without seeing one, and sent only as far as the
 * taxpayer's own consented provider.
 *
 * ## Why every answer is checked
 *
 * The schema only promises the answer has the right *shape*. Three things it can still get wrong
 * would each produce a form that silently reads the wrong thing:
 *
 *  - **a column that does not exist**, which reads nothing;
 *  - **a label the file never wrote**, same;
 *  - **a drop-pattern that matches a detail line**, which is the dangerous one. A pattern meant for
 *    a page footer that also matches `Tranzactie la:` would delete the counterparty of every card
 *    payment in the file, and the totals would still look right. So a pattern matching any labelled
 *    line, or any line that opens a transaction, is thrown away and the rest of the answer kept.
 *
 * ## It is still only a suggestion
 *
 * The answer is prefilled into the mapping screen and a person confirms it against a preview of real
 * rows. A model that mistakes a running balance for the amount is obvious there and invisible in a
 * ledger, which is the entire reason the confirmation step exists.
 */
final readonly class SuggestColumnMapping
{
    /** A cell that carries a label — the shape the reader looked for, and the builder folds in. */
    private const string LABELLED_CELL = '/^\s*([^:]{2,45}?)\s*:\s*(.*)$/u';

    /**
     * What a model calls a date format, against what the parser calls it.
     *
     * Models answer in whichever convention they were trained on, and `d MMMM yyyy` is the same
     * request as `D MONTH YYYY`. Translating is cheaper than refusing a correct answer for its
     * spelling.
     */
    private const array FORMAT_ALIASES = [
        'D MMMM YYYY' => 'D MONTH YYYY',
        'DD MMMM YYYY' => 'D MONTH YYYY',
        'D MMM YYYY' => 'D MONTH YYYY',
        'D MONTHNAME YYYY' => 'D MONTH YYYY',
        'DD/MM/YY' => 'DD.MM.YY',
        'YYYY.MM.DD' => 'YYYY-MM-DD',
    ];

    public function __construct(
        private StatementAiClient $ai,
        private ConfigRepository $config,
    ) {
    }

    public function __invoke(string $subjectId, ParsedStatementFile $file): FormSuggestion
    {
        if ($file->columns === []) {
            return FormSuggestion::failed(FormSuggestion::NOT_THIS_FILE);
        }

        $answer = $this->ai->ask(
            $subjectId,
            $this->config->string('statement_import.prompts.mapping_system'),
            $this->config->string('statement_import.prompts.mapping_instruction'),
            $this->payload($file),
            self::schema(),
            $this->config->string('statement_import.ai.model'),
            $this->config->integer('statement_import.ai.max_tokens'),
            $this->config->integer('statement_import.ai.timeout_seconds'),
        );

        if (! $answer->succeeded() || $answer->data === null) {
            return FormSuggestion::failed($answer->failure ?? AiAnswer::UNREADABLE);
        }

        $form = $this->toForm($answer->data, $file);

        /*
         * The reply travels with the answer, usable or not.
         *
         * A form that reads nothing and a form that was never proposed look identical on screen, and
         * the difference is in what the model actually said — which column it named, which label it
         * invented, which drop-pattern was thrown away for matching a detail line. Kept with the
         * import so that question is answerable without re-running anything.
         */
        return $form === null
            ? FormSuggestion::failed(FormSuggestion::NOT_THIS_FILE, $answer->data)
            : FormSuggestion::of($form, $answer->data);
    }

    /**
     * Everything the model needs to answer, and nothing else.
     *
     * @return array<string, mixed>
     */
    private function payload(ParsedStatementFile $file): array
    {
        $sampleRows = array_slice($file->rows, 0, max(1, $this->config->integer('statement_import.limits.preview_rows')));

        return [
            'row_count' => count($file->rows),
            'columns' => array_map(
                static fn ($column): array => [
                    'i' => $column->index,
                    'header' => $column->header,
                    'shape' => $column->shape,
                    'filled' => $column->filledRatio,
                ],
                $file->columns,
            ),
            'labels_by_amount_column' => array_map(
                static fn ($group): array => $group->toArray(),
                $file->labelGroups,
            ),
            // The lines as the file has them, cells separated, so a detail line is visibly a
            // continuation of the line above rather than a transaction of its own.
            'sample_lines' => array_map(
                static fn (array $row): string => implode(' | ', $row),
                $sampleRows,
            ),
        ];
    }

    /**
     * The shape the reply must have.
     *
     * Deliberately permissive about *which* index or label: those are checked against the file
     * afterwards, where a wrong one can be told from a merely unexpected one. The schema's job is to
     * make the reply parseable at all, which is what a local model needs help with.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $field = [
            'type' => ['object', 'null'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => [StatementField::INDEX, StatementField::LABEL, StatementField::CODE]],
                'value' => ['type' => ['integer', 'string']],
            ],
            'required' => ['type', 'value'],
        ];

        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'required' => ['layout', 'date', 'amounts'],
            'properties' => [
                'layout' => ['type' => 'string', 'enum' => [StatementForm::LAYOUT_ROW, StatementForm::LAYOUT_BLOCK]],
                'date' => [
                    'type' => 'object',
                    'required' => ['value', 'format'],
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'value' => ['type' => 'integer'],
                        'format' => ['type' => 'string'],
                    ],
                ],
                'currency' => $field,
                'amounts' => [
                    'type' => 'object',
                    'required' => ['mode'],
                    'properties' => [
                        'mode' => ['type' => 'string', 'enum' => [StatementForm::MODE_SIGNED, StatementForm::MODE_DEBIT_CREDIT]],
                        'amount' => $field,
                        'debit' => $field,
                        'credit' => $field,
                        'decimal_separator' => ['type' => 'string'],
                        'thousands_separator' => ['type' => ['string', 'null']],
                    ],
                ],
                'description' => $field,
                'counterparty' => [
                    'type' => 'object',
                    'properties' => ['debit' => $field, 'credit' => $field],
                ],
                'reference' => [
                    'type' => 'object',
                    'properties' => ['debit' => $field, 'credit' => $field],
                ],
                'non_transaction_lines' => $strings,
                'identity_labels' => $strings,
            ],
        ];
    }

    /**
     * The reply as a form, or null when it does not describe this file.
     *
     * @param  array<string, mixed>  $answer
     */
    private function toForm(array $answer, ParsedStatementFile $file): ?StatementForm
    {
        $answer['date'] = is_array($answer['date'] ?? null) ? $answer['date'] : [];
        $answer['date']['format'] = $this->format($answer['date']['format'] ?? null);
        $answer['non_transaction_lines'] = $this->usableDropPatterns($answer['non_transaction_lines'] ?? null, $file);

        $form = StatementForm::fromArray($answer);
        $columns = count($file->columns);
        $labels = array_map(LabelKey::for(...), $file->labelNames());

        $known = static function (?StatementField $field) use ($columns, $labels): ?StatementField {
            if ($field === null) {
                return null;
            }

            // A column the file does not have, or a label it never wrote, reads nothing — and a form
            // field that reads nothing is better empty, so the screen asks rather than lies.
            return match (true) {
                $field->isIndex() => ($field->column() ?? -1) >= 0 && ($field->column() ?? $columns) < $columns ? $field : null,
                $field->isLabel() => in_array(LabelKey::for((string) $field->value), $labels, true) ? $field : null,
                default => $field,
            };
        };

        if ($form->dateColumn < 0 || $form->dateColumn >= $columns) {
            return null;
        }

        $inFile = static fn (?int $column): ?int => $column !== null && $column >= 0 && $column < $columns ? $column : null;
        $amount = $inFile($form->amountColumn);
        $debit = $inFile($form->debitColumn);
        $credit = $inFile($form->creditColumn);

        if ($form->amountMode === StatementForm::MODE_SIGNED && $amount === null) {
            return null;
        }

        if ($form->amountMode === StatementForm::MODE_DEBIT_CREDIT && $debit === null && $credit === null) {
            return null;
        }

        return new StatementForm(
            layout: $form->layout,
            dateColumn: $form->dateColumn,
            dateFormat: $form->dateFormat,
            amountMode: $form->amountMode,
            amountColumn: $amount,
            debitColumn: $debit,
            creditColumn: $credit,
            decimalSeparator: $form->decimalSeparator,
            thousandsSeparator: $form->thousandsSeparator,
            currency: $known($form->currency),
            description: $known($form->description),
            counterpartyOut: $known($form->counterpartyOut),
            counterpartyIn: $known($form->counterpartyIn),
            referenceOut: $known($form->referenceOut),
            referenceIn: $known($form->referenceIn),
            nonTransactionLines: $form->nonTransactionLines,
            identityLabels: array_values(array_filter(
                $form->identityLabels,
                static fn (string $label): bool => in_array(LabelKey::for($label), $labels, true),
            )),
        );
    }

    /** A date format the parser knows, translating the spellings that mean the same thing. */
    private function format(mixed $value): string
    {
        $named = strtoupper(trim(is_string($value) ? $value : ''));
        $named = self::FORMAT_ALIASES[$named] ?? $named;

        return in_array($named, StatementForm::DATE_FORMATS, true) ? $named : 'DD.MM.YYYY';
    }

    /**
     * The drop-patterns that are safe to apply, which is not all of them.
     *
     * A pattern is kept only if it matches nothing in the sample that carries data. Anything that
     * matches a labelled line or a line opening a transaction is discarded, because applying it
     * would delete a payment's details or the payment itself — and a statement that imports with the
     * counterparty missing from every card payment looks fine until someone needs it.
     *
     * @return list<string>
     */
    private function usableDropPatterns(mixed $patterns, ParsedStatementFile $file): array
    {
        if (! is_array($patterns)) {
            return [];
        }

        $protected = [];

        foreach ($file->rows as $row) {
            $leading = '';
            $carriesData = false;

            foreach ($row as $cell) {
                $trimmed = trim($cell);

                if ($trimmed === '') {
                    continue;
                }

                if ($leading === '') {
                    $leading = LabelKey::for($trimmed);
                }

                if (preg_match(self::LABELLED_CELL, $trimmed) === 1) {
                    $carriesData = true;
                }
            }

            if ($carriesData && $leading !== '') {
                $protected[] = $leading;
            }
        }

        $kept = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            $prefix = LabelKey::for($pattern);

            foreach ($protected as $line) {
                if ($prefix !== '' && str_starts_with($line, $prefix)) {
                    continue 2;
                }
            }

            $kept[] = trim($pattern);
        }

        return $kept;
    }
}
