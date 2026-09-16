<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Domain\StatementImport\DataTransferObjects\BuiltStatementRows;
use App\Domain\StatementImport\DataTransferObjects\StatementField;
use App\Domain\StatementImport\DataTransferObjects\StatementForm;
use App\Domain\StatementImport\Exceptions\MappingNotUsableException;

/**
 * Applying a confirmed form to a statement's lines, to get transactions.
 *
 * ## A transaction is not always a line
 *
 * In the shape a Romanian bank exports, one payment is a line carrying the date and the amount,
 * followed by its own detail lines carrying everything else:
 *
 * ```
 * 12 septembrie 2026  Cumparare POS   155,47
 *                     Tranzactie la: DIGI ROMANIA SA
 *                     Numar autorizare: 004521
 * ```
 *
 * The counterparty is not in the first line and never will be. So a line with a date and an amount
 * *opens* a transaction, every line after it belongs to that transaction until the next one opens,
 * and the `Label: value` pairs beneath are folded in as the fields the form named. Discarding those
 * lines — which is what reading each line on its own amounts to — throws away the merchant, the
 * reference and the payer, and leaves 1 700 transactions that all say "Cumparare POS".
 *
 * A file with one transaction per line takes the same path with no detail lines: `layout: row` reads
 * each line alone, which is the degenerate case of the same loop rather than a second code path.
 *
 * ## Why it refuses a form that mostly works
 *
 * A form is one answer applied to thousands of lines. If it reads four openers in five, the fifth
 * would arrive as a transaction with no date, or with an amount read under the wrong decimal
 * separator — a silent factor of a hundred. So a form below the configured share is refused with
 * both counts, and the person fixes the field rather than importing most of a statement.
 */
final readonly class StatementRowBuilder
{
    /** `Tranzactie la: DIGI ROMANIA SA` — a label, then its value. */
    private const string LABELLED_CELL = '/^\s*([^:]{2,45}?)\s*:\s*(.*)$/u';

    public function __construct(private StatementValueParser $values)
    {
    }

    /**
     * @param  list<list<string>>  $rows  the file's lines, as text
     *
     * @throws MappingNotUsableException when too few of the lines that look like transactions can be read
     */
    public function build(array $rows, StatementForm $form, float $threshold, ?string $accountCurrency): BuiltStatementRows
    {
        $built = [];
        $candidates = 0;
        $readable = 0;
        $folded = 0;
        $skipped = 0;

        /** @var list<string>|null $open the line that opened the current transaction */
        $open = null;
        /** @var list<list<string>> $details its detail lines */
        $details = [];
        $openNumber = 0;

        $close = function () use (&$open, &$details, &$openNumber, &$built, $form, $accountCurrency): void {
            if ($open === null) {
                return;
            }

            $built[] = $this->transaction($open, $details, $openNumber, $form, $accountCurrency);
            $open = null;
            $details = [];
        };

        foreach ($rows as $index => $row) {
            if ($this->isNonTransactionLine($row, $form)) {
                $skipped++;

                continue;
            }

            $opens = $this->opensTransaction($row, $form);

            if ($opens) {
                $candidates++;
            }

            // In block layout a line that is not an opener belongs to the transaction above it. In
            // row layout there is nothing above it, so it is a line that could not be read.
            if (! $opens && $form->isBlock() && $open !== null) {
                $details[] = $row;
                $folded++;

                continue;
            }

            $close();

            if (! $opens && ! $form->isBlock()) {
                // Row layout reads every line, readable or not: the review screen shows the ones it
                // could not read so a person can see what was in the file.
                $built[] = $this->transaction($row, [], $index + 1, $form, $accountCurrency);

                continue;
            }

            if (! $opens) {
                $skipped++;

                continue;
            }

            $readable++;
            $open = $row;
            $openNumber = $index + 1;

            if (! $form->isBlock()) {
                $close();
            }
        }

        $close();

        if ($candidates > 0 && $readable / $candidates < $threshold) {
            throw new MappingNotUsableException($readable, $candidates);
        }

        // Nothing at all was read: a wrong date format or a form built for another file. Refused the
        // same way, because importing zero transactions from a statement is never what was meant.
        if ($readable === 0 && $rows !== []) {
            throw new MappingNotUsableException(0, count($rows));
        }

        return new BuiltStatementRows($built, count($rows), $folded, $skipped);
    }

    /**
     * One transaction: the line that opened it, plus whatever its detail lines carried.
     *
     * @param  list<string>  $cells
     * @param  list<list<string>>  $details
     * @return array{row_number: int, booked_at: ?string, amount_minor: ?int, currency_code: ?string, text: string, counterparty: string, reference: string}
     */
    private function transaction(array $cells, array $details, int $rowNumber, StatementForm $form, ?string $accountCurrency): array
    {
        $labels = $this->labels($details, $form);
        $amount = $this->values->rowAmountMinor($cells, $form);

        return [
            'row_number' => $rowNumber,
            'booked_at' => $this->values->date($cells[$form->dateColumn] ?? '', $form->dateFormat),
            'amount_minor' => $amount,
            'currency_code' => $this->currency($cells, $labels, $form, $accountCurrency),
            'text' => $this->field($form->description, $cells, $labels),
            // Which side the money moved decides which labels are asked for: a statement names the
            // counterparty of a card payment and of an incoming transfer differently.
            'counterparty' => $this->field($form->counterpartyFor($amount ?? 0), $cells, $labels),
            'reference' => $this->field($form->referenceFor($amount ?? 0), $cells, $labels),
        ];
    }

    /**
     * The `Label: value` pairs under one transaction.
     *
     * The first of a repeated label wins: a bank writes the important one first, and a later
     * repetition is a continuation rather than a correction.
     *
     * Identity labels are never collected. A personal number or a card number is an identifier the
     * ledger has no use for, and the cheapest way to be sure it is never stored is to not carry it
     * out of this loop.
     *
     * @param  list<list<string>>  $details
     * @return array<string, string> keyed by {@see LabelKey}
     */
    private function labels(array $details, StatementForm $form): array
    {
        $identity = array_map(LabelKey::for(...), $form->identityLabels);
        $labels = [];

        foreach ($details as $row) {
            foreach ($row as $cell) {
                if (preg_match(self::LABELLED_CELL, trim($cell), $matches) !== 1) {
                    continue;
                }

                $key = LabelKey::for($matches[1]);
                $value = trim($matches[2]);

                if ($value === '' || in_array($key, $identity, true) || array_key_exists($key, $labels)) {
                    continue;
                }

                $labels[$key] = $value;
            }
        }

        return $labels;
    }

    /**
     * Whether this line starts a transaction: it carries a date where the form says, and an amount.
     *
     * Both are required. A detail line can hold a number — an exchange rate, an authorisation code —
     * and a heading can hold a date, and either alone would open a transaction that has nothing in it.
     *
     * @param  list<string>  $cells
     */
    private function opensTransaction(array $cells, StatementForm $form): bool
    {
        if ($this->values->date($cells[$form->dateColumn] ?? '', $form->dateFormat) === null) {
            return false;
        }

        $amount = $this->values->rowAmountMinor($cells, $form);

        return $amount !== null && $amount !== 0;
    }

    /**
     * A heading, a footer, or an account-detail line the form named.
     *
     * Matched against the line's first text, never anywhere inside it: a pattern that could match
     * mid-line would swallow a detail line whose text happens to begin the same way, and a swallowed
     * detail line is a transaction that loses its counterparty.
     *
     * @param  list<string>  $cells
     */
    private function isNonTransactionLine(array $cells, StatementForm $form): bool
    {
        if ($form->nonTransactionLines === []) {
            return false;
        }

        $text = '';

        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                $text = LabelKey::for($cell);

                break;
            }
        }

        if ($text === '') {
            return false;
        }

        foreach ($form->nonTransactionLines as $pattern) {
            $prefix = LabelKey::for($pattern);

            if ($prefix !== '' && str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What one field holds: a cell of this line, or a label from beneath it.
     *
     * @param  list<string>  $cells
     * @param  array<string, string>  $labels
     */
    private function field(?StatementField $field, array $cells, array $labels): string
    {
        if ($field === null) {
            return '';
        }

        $column = $field->column();

        if ($column !== null) {
            return trim($cells[$column] ?? '');
        }

        $label = $field->labelName();

        return $label === null ? '' : ($labels[LabelKey::for($label)] ?? '');
    }

    /**
     * The transaction's currency: what the file says, else what the form fixed, else the account's own.
     *
     * Falling back to the account is safe in the one case it matters — a statement of a single
     * account in a single currency, which is most of them — and a source holding several currencies
     * carries none, so a file without a currency leaves it null and the review screen asks.
     *
     * @param  list<string>  $cells
     * @param  array<string, string>  $labels
     */
    private function currency(array $cells, array $labels, StatementForm $form, ?string $accountCurrency): ?string
    {
        $value = strtoupper($this->field($form->currency, $cells, $labels));

        if (preg_match('/^[A-Z]{3}$/', $value) === 1) {
            return $value;
        }

        return $accountCurrency;
    }
}
