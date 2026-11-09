<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\Parsing\HtmlTableTest;

use Kern\Investments\Parsing\Html\HtmlPage;
use Kern\Investments\Parsing\Html\HtmlTable;
use Kern\Investments\Parsing\ParseFailure;

/** @return list<array<string, string>> */
function read(string $html, array $headers): array
{
    return array_map(
        static fn (array $row): array => array_map(HtmlPage::text(...), $row),
        HtmlTable::withHeaders(HtmlPage::load($html), $headers, 'test'),
    );
}

it('reads each cell under its header, wherever the source put the column', function (): void {
    $html = '<table><tr><th>B</th><th>Extra</th><th>A</th></tr><tr><td>2</td><td>x</td><td>1</td></tr></table>';

    expect(read($html, ['A', 'B']))->toBe([['A' => '1', 'B' => '2']]);
});

it('fails naming the columns when a header is renamed or gone', function (): void {
    expect(fn () => read('<table><tr><th>A</th><th>Bee</th></tr><tr><td>1</td><td>2</td></tr></table>', ['A', 'B']))
        ->toThrow(ParseFailure::class, 'test: no table with the columns A, B');
});

it('fails a row shorter than its header', function (): void {
    expect(fn () => read('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td></tr></table>', ['A', 'B']))
        ->toThrow(ParseFailure::class, 'fewer cells');
});

it('keeps a nested table’s rows out of the table around it', function (): void {
    $html = '<table><tr><th>A</th></tr><tr><td><table><tr><td>inner</td></tr></table>outer</td></tr></table>';

    expect(HtmlTable::rows(HtmlPage::load($html)->querySelector('table')))->toHaveCount(2);
});

it('reads markup in a cell as text, never as markup', function (): void {
    $html = '<table><tr><th>A</th></tr><tr><td><script>alert(1)</script><b>Bold</b>&nbsp;name</td></tr></table>';

    expect(read($html, ['A']))->toBe([['A' => 'alert(1)Bold name']]);
});

it('bounds a cell’s text', function (): void {
    $html = '<table><tr><th>A</th></tr><tr><td>' . str_repeat('x', 5000) . '</td></tr></table>';

    expect(mb_strlen(read($html, ['A'])[0]['A']))->toBe(HtmlPage::MAX_TEXT);
});
