<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementForm;

/**
 * Turning what a bank printed into what the ledger stores: a date, and an amount in minor units.
 *
 * ## Nothing is guessed here
 *
 * Every question this could get wrong — which character is the decimal separator, whether `03.04` is
 * March or April, whether money out is a minus or its own column — is answered by the mapping a
 * person confirmed against a preview. This applies that answer and nothing else, so a row that does
 * not fit comes back as null and is counted rather than quietly becoming a wrong figure.
 */
final readonly class StatementValueParser
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /** "12 septembrie 2026": a day, a month written as a word, a year. */
    private const string WORDED_DATE = '/^(\d{1,2})\s+([^\W\d_]{3,12})\.?\s+(\d{4})$/u';

    /** How the reader names a date's shape, and what each part means. */
    private const array DATE_FORMATS = [
        'DD.MM.YYYY' => '/^(\d{1,2})\.(\d{1,2})\.(\d{4})/',
        'DD/MM/YYYY' => '#^(\d{1,2})/(\d{1,2})/(\d{4})#',
        'DD-MM-YYYY' => '/^(\d{1,2})-(\d{1,2})-(\d{4})/',
        'MM/DD/YYYY' => '#^(\d{1,2})/(\d{1,2})/(\d{4})#',
        'YYYY-MM-DD' => '/^(\d{4})-(\d{1,2})-(\d{1,2})/',
        'YYYY/MM/DD' => '#^(\d{4})/(\d{1,2})/(\d{1,2})#',
        'DD.MM.YY' => '/^(\d{1,2})\.(\d{1,2})\.(\d{2})(?!\d)/',
    ];

    /**
     * A date as `YYYY-MM-DD`, or null when this value is not the date the mapping promised.
     *
     * A time after the date is ignored rather than refused: plenty of exports write
     * "05.03.2026 14:32", and a transaction is booked on a day.
     */
    public function date(string $value, string $format): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($format === 'D MONTH YYYY') {
            return $this->wordedDate($value);
        }

        $pattern = self::DATE_FORMATS[$format] ?? null;

        if ($pattern === null || preg_match($pattern, $value, $matches) !== 1) {
            return null;
        }

        [$year, $month, $day] = match ($format) {
            'YYYY-MM-DD', 'YYYY/MM/DD' => [(int) $matches[1], (int) $matches[2], (int) $matches[3]],
            'MM/DD/YYYY' => [(int) $matches[3], (int) $matches[1], (int) $matches[2]],
            // A two-digit year is this century: a bank statement is not a historical document, and
            // "26" meaning 1926 would put a transaction a hundred years out.
            'DD.MM.YY' => [2000 + (int) $matches[3], (int) $matches[2], (int) $matches[1]],
            default => [(int) $matches[3], (int) $matches[2], (int) $matches[1]],
        };

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)?->toDateString();
    }

    /**
     * A date whose month is a word, read against the names in `config/reference/months.php`.
     *
     * The month is matched without diacritics and in lower case: an export may write "Septembrie",
     * "septembrie" or "SEPTEMBRIE", and none of those is a different month.
     */
    private function wordedDate(string $value): ?string
    {
        if (preg_match(self::WORDED_DATE, $value, $matches) !== 1) {
            return null;
        }

        /** @var array<string, int> $months */
        $months = $this->config->array('reference.months');
        $name = LabelKey::for($matches[2]);
        $month = null;

        foreach ($months as $candidate => $number) {
            if (LabelKey::for((string) $candidate) === $name) {
                $month = (int) $number;

                break;
            }
        }

        if ($month === null) {
            return null;
        }

        $day = (int) $matches[1];
        $year = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)?->toDateString();
    }

    /**
     * An amount in minor units, or null when the value is not a number under this mapping.
     *
     * Handles the three ways an export writes a negative — a leading minus, a trailing minus, and
     * parentheses — and strips a currency code or symbol printed in the same cell.
     */
    public function amountMinor(string $value, StatementForm $mapping): ?int
    {
        $raw = trim($value);

        if ($raw === '') {
            return null;
        }

        $negative = str_starts_with($raw, '-') || str_ends_with($raw, '-') || str_starts_with($raw, '(');

        // Everything that is not a digit or a separator: currency codes, symbols, spaces, the signs
        // already accounted for above.
        $body = preg_replace('/[^\d' . preg_quote($mapping->decimalSeparator . $mapping->thousandsSeparator, '/') . ']/u', '', $raw) ?? '';

        if ($mapping->thousandsSeparator !== '') {
            $body = str_replace($mapping->thousandsSeparator, '', $body);
        }

        if ($mapping->decimalSeparator !== '') {
            $body = str_replace($mapping->decimalSeparator, '.', $body);
        }

        if ($body === '' || ! is_numeric($body)) {
            return null;
        }

        // Rounded once, here: a statement writes two decimals, and float arithmetic on money is only
        // safe when it ends immediately in an integer of minor units.
        $minor = (int) round((float) $body * 100);

        return $negative ? -abs($minor) : $minor;
    }

    /**
     * The row's amount under the mapping, whichever shape the export uses.
     *
     * In `debit_credit` mode the columns are unsigned and their meaning is their position: the debit
     * column is money leaving, so it becomes negative, and the credit column is money arriving. A row
     * with a figure in both is not a transaction this can read.
     *
     * @param  list<string>  $row
     */
    public function rowAmountMinor(array $row, StatementForm $mapping): ?int
    {
        $cell = static fn (?int $index): string => $index !== null && isset($row[$index]) ? $row[$index] : '';

        if ($mapping->amountMode === StatementForm::MODE_SIGNED) {
            return $this->amountMinor($cell($mapping->amountColumn), $mapping);
        }

        $debit = $this->amountMinor($cell($mapping->debitColumn), $mapping);
        $credit = $this->amountMinor($cell($mapping->creditColumn), $mapping);

        if ($debit !== null && $debit !== 0 && $credit !== null && $credit !== 0) {
            return null;
        }

        if ($debit !== null && $debit !== 0) {
            return -abs($debit);
        }

        if ($credit !== null && $credit !== 0) {
            return abs($credit);
        }

        return null;
    }

}
