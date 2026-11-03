<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Parsing;

use Kern\TaxRomania\Banks\Parsing\Exceptions\InvalidBankSourceFileException;
use RuntimeException;

/**
 * Rows of a delimited text file, keyed by its header, with the columns the caller relies on checked
 * up front.
 *
 * A missing column fails the whole file instead of reading as empty values — an empty `COUNTRY`
 * column would otherwise filter out every bank and the import would mark them all inactive.
 */
final class DelimitedRows
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * CSV as GLEIF writes it: quoted fields that may contain the delimiter or a line break.
     *
     * The separator is a comma unless the caller says otherwise — a spreadsheet saved in a Romanian
     * locale writes `;`, because there the comma is the decimal separator.
     *
     * @param  list<string>  $required
     * @return list<array<string, string>>
     */
    public static function fromCsv(string $text, array $required, string $file, string $separator = ','): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream.');
        }

        fwrite($stream, self::withoutBom($text));
        rewind($stream);

        $records = [];

        // An empty escape character: RFC 4180 escapes a quote by doubling it, never by backslash,
        // and PHP's default backslash would corrupt a legal name ending in one.
        while (($record = fgetcsv($stream, null, $separator, '"', '')) !== false) {
            $records[] = array_map(static fn (?string $cell): string => trim((string) $cell), $record);
        }

        fclose($stream);

        return self::keyed($records, $required, $file);
    }

    /**
     * Tab-separated text as the ECB writes it: one record per line, no quoting. Split by hand
     * rather than through `fgetcsv`, which would treat a legal name beginning with a quote mark as
     * the start of a quoted field and swallow the rest of the file into it.
     *
     * @param  list<string>  $required
     * @return list<array<string, string>>
     */
    public static function fromTsv(string $text, array $required, string $file): array
    {
        $lines = preg_split('/\r\n|\n|\r/', self::withoutBom($text));

        $records = array_map(
            static fn (string $line): array => array_map('trim', explode("\t", $line)),
            array_values(array_filter($lines === false ? [] : $lines, static fn (string $line): bool => trim($line) !== '')),
        );

        return self::keyed($records, $required, $file);
    }

    /**
     * @param  list<list<string>>  $records
     * @param  list<string>  $required
     * @return list<array<string, string>>
     */
    private static function keyed(array $records, array $required, string $file): array
    {
        $header = array_shift($records) ?? [];
        $missing = array_values(array_diff($required, $header));

        if ($missing !== []) {
            throw InvalidBankSourceFileException::missingColumns($file, $missing);
        }

        $rows = [];

        foreach ($records as $record) {
            // A blank trailing line reads as one empty cell; a short record is padded, not dropped,
            // so a row missing only its last optional value still counts.
            if ($record === [''] || $record === []) {
                continue;
            }

            $row = [];

            foreach ($header as $index => $column) {
                $row[$column] = $record[$index] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private static function withoutBom(string $text): string
    {
        return str_starts_with($text, self::UTF8_BOM) ? substr($text, strlen(self::UTF8_BOM)) : $text;
    }
}
