<?php

declare(strict_types=1);

namespace Kern\Platform\Text;

/**
 * The two conventions every CSV this application hands out follows.
 *
 * Here rather than in a module because the second exporter wanted the same two, and a spreadsheet
 * that opens one of our files should behave the same way for all of them — the same place and the
 * same reason `Text\PartyName` lives here.
 *
 * ## A BOM
 *
 * Excel on Windows reads a UTF-8 file without one as the system codepage and turns every Romanian
 * diacritic into mojibake. The file stays valid UTF-8 for everything else.
 *
 * ## Amounts written plainly
 *
 * `1234.56` — dot decimal, no grouping, no currency in the cell. A spreadsheet reads that as a
 * number in every locale; `1.234,56 RON` is text in most of them, and a column of text that looks
 * like money is worse than a column of numbers needing a header to explain itself. The currency
 * belongs in its own column.
 */
final class CsvFile
{
    /**
     * @param  list<list<string>>  $rows
     */
    public static function fromRows(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\u{FEFF}");

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /** Minor units as a cell a spreadsheet reads as a number. */
    public static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
