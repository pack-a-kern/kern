<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kern\TaxRomania\Banks\DataTransferObjects\BankOption;
use Kern\TaxRomania\Banks\Parsing\DelimitedRows;
use Kern\TaxRomania\Banks\Queries\BankDirectory;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementForm;
use Kern\TaxRomania\StatementImport\Services\StatementValueParser;

/**
 * `POST /api/v1/subjects/{subject}/wallet/deposits/csv` — read a CSV of term deposits, and write nothing.
 *
 * The first half of adding deposits in bulk. It answers two questions and keeps no copy of the file:
 * which columns there are, and — once a person has said which column is which — what each line reads
 * as. The deposits themselves are opened by {@see OpenDepositsController}, which validates every one
 * exactly as the account form validates a single deposit. Nothing here decides whether a deposit is
 * acceptable; this only reads what the file says.
 *
 * ## Why the reading happens here and not in the browser
 *
 * Dates and amounts are read by {@see StatementValueParser}, the same code that reads a bank
 * statement: `1.234,56` is one figure under one rule everywhere in the application. A second reader
 * in the browser would be a second rule.
 *
 * ## What the file can cost
 *
 * Read in memory and dropped with the request: never stored, never sent anywhere, and so not
 * scanned for viruses — nothing but `fgetcsv` ever opens it. Bounded in size and in lines by
 * `wallet.deposit.import`, checked before a line is read. UTF-8 only: a guess at another encoding is
 * a bank name that matches nothing for reasons nobody can see.
 */
final class ReadDepositCsvController
{
    /** The fields a column can be named for; everything else on a deposit is one answer for the file. */
    private const array COLUMNS = ['starts_at', 'principal', 'annual_rate', 'term_months', 'bank', 'currency'];

    /** The lines shown before anything is mapped — enough to recognise a column by its values. */
    private const int SAMPLE_LINES = 5;

    public function __invoke(
        Request $request,
        ConfigRepository $config,
        StatementValueParser $values,
        BankDirectory $banks,
    ): JsonResponse {
        $mappingRules = [];

        foreach (self::COLUMNS as $field) {
            $mappingRules['mapping.' . $field] = ['sometimes', 'nullable', 'string', 'max:200'];
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:csv', 'max:' . intdiv($config->integer('wallet.deposit.import.max_file_bytes'), 1024)],
            'separator' => ['required', Rule::in([',', ';'])],
            'date_format' => ['required', Rule::in(StatementForm::DATE_FORMATS)],
            'decimal_separator' => ['required', Rule::in([',', '.'])],
            'mapping' => ['sometimes', 'array'],
            ...$mappingRules,
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $text = (string) $file->get();

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => [__('tax-romania::wallet.deposit_csv.not_utf8')]]);
        }

        $lines = DelimitedRows::fromCsv($text, [], 'deposits.csv', (string) $validated['separator']);
        $maxRows = $config->integer('wallet.deposit.import.max_rows');

        if ($lines === []) {
            throw ValidationException::withMessages(['file' => [__('tax-romania::wallet.deposit_csv.empty')]]);
        }

        if (count($lines) > $maxRows) {
            throw ValidationException::withMessages(['file' => [__('tax-romania::wallet.deposit_csv.too_many', ['max' => $maxRows])]]);
        }

        $headers = array_keys($lines[0]);
        /** @var array<string, string|null> $mapping */
        $mapping = is_array($validated['mapping'] ?? null) ? $validated['mapping'] : [];

        foreach ($mapping as $field => $column) {
            if ($column !== null && $column !== '' && ! in_array($column, $headers, true)) {
                throw ValidationException::withMessages(['mapping.' . $field => [__('tax-romania::wallet.deposit_csv.no_such_column')]]);
            }
        }

        $offered = $banks->active(ShowBanksController::OFFERED);
        $decimal = (string) $validated['decimal_separator'];
        // The other of the two: a file that writes `1.234,56` cannot also mean `1,234.56`.
        $thousands = $decimal === ',' ? '.' : ',';

        $rows = [];

        foreach ($lines as $index => $line) {
            $cell = static fn (string $field): ?string => isset($mapping[$field]) && $mapping[$field] !== ''
                ? ($line[$mapping[$field]] ?? '')
                : null;

            $rows[] = [
                // The file's own line number, header included, so a person can find it.
                'line' => $index + 2,
                'values' => [
                    'starts_at' => self::read($cell('starts_at'), static fn (string $value): ?string => $values->date($value, (string) $validated['date_format'])),
                    'principal_minor' => self::read($cell('principal'), static fn (string $value): ?int => $values->amountMinor($value, $decimal, $thousands)),
                    'annual_rate' => self::read($cell('annual_rate'), static fn (string $value): ?string => self::rate($value, $decimal)),
                    'term_months' => self::read($cell('term_months'), self::months(...)),
                    'bank_id' => self::read($cell('bank'), static fn (string $value): ?string => self::bank($value, $offered)),
                    'currency_code' => self::read($cell('currency'), static fn (string $value): ?string => $value === '' ? null : strtoupper($value)),
                ],
            ];
        }

        return response()->json([
            'headers' => $headers,
            'sample' => array_map(
                static fn (array $line): array => array_values($line),
                array_slice($lines, 0, self::SAMPLE_LINES),
            ),
            'rows' => $rows,
        ]);
    }

    /**
     * What one mapped cell reads as: absent when no column was named for it, and `unreadable` beside
     * the cell's own text when the column was named and the value is not what it should be — so the
     * person sees `31.02.2027` and knows which line to correct, rather than a blank.
     *
     * @param  callable(string): (string|int|null)  $parse
     * @return array{value: string|int|null, cell: string}|null
     */
    private static function read(?string $cell, callable $parse): ?array
    {
        if ($cell === null) {
            return null;
        }

        return ['value' => $parse(trim($cell)), 'cell' => $cell];
    }

    /**
     * A rate as the account form sends it: a percentage, `.` for the decimal point, nothing else.
     *
     * A `%` or a space around the figure is how a spreadsheet writes one and is dropped; anything
     * else is left for the deposit's own rule to refuse, which is where the form's input is refused.
     */
    private static function rate(string $value, string $decimal): ?string
    {
        $rate = str_replace([' ', '%'], '', $value);

        return $rate === '' ? null : str_replace($decimal, '.', $rate);
    }

    /** A whole, positive number of months, or nothing. */
    private static function months(string $value): ?int
    {
        return preg_match('/^\d{1,3}$/', $value) === 1 && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * The bank a cell names, from the list the account form offers: by its name or its code, in any
     * case. A name that is not on the list is not guessed at — it is the line a person corrects.
     *
     * @param  list<BankOption>  $offered
     */
    private static function bank(string $value, array $offered): ?string
    {
        $wanted = mb_strtolower($value);

        foreach ($offered as $bank) {
            if (mb_strtolower($bank->name) === $wanted || ($bank->code !== null && mb_strtolower($bank->code) === $wanted)) {
                return $bank->id;
            }
        }

        return null;
    }
}
