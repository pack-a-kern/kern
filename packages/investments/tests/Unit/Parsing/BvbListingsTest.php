<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\Parsing\BvbListingsTest;

use DateTimeZone;
use Kern\Investments\Parsing\Bvb\BvbBondType;
use Kern\Investments\Parsing\Bvb\BvbListings;
use Kern\Investments\Parsing\Bvb\ListedBond;
use Kern\Investments\Parsing\ParseFailure;

function listings(): BvbListings
{
    return new BvbListings(new DateTimeZone('Europe/Bucharest'));
}

function page(string $name): string
{
    return (string) file_get_contents(__DIR__ . "/../../Fixtures/bvb/{$name}.html");
}

/**
 * A bond list of one row, as BVB marks one up, with the cells given by position made hostile.
 *
 * @param array<int, string> $cells
 * @param array<int, string> $headers
 */
function bondPage(array $cells = [], array $headers = []): string
{
    $headers = array_replace(['Simbol / ISIN', 'Societate', 'Pret (%)', 'Var. (%)', 'Data', 'Cupon curent (%)', 'Moneda de emisiune', 'Data maturitatii', 'Tip'], $headers);
    $cells = array_replace([
        '<a href="#"><b>R2899A</b></a><p>ROTLVAACNOR1</p>', 'EXAMPLE ISSUER', '100,9000', '0,90', '09.11.2026 17:27', '8,2500', 'RON', '16.07.2027', 'Titluri de stat',
    ], $cells);

    return '<table id="gv"><thead><tr>' . implode('', array_map(fn ($h) => "<th>{$h}</th>", $headers)) . '</tr></thead><tbody><tr>'
        . implode('', array_map(fn ($c) => "<td>{$c}</td>", $cells)) . '</tr></tbody></table>';
}

it('reads every listed share from BVB’s real page', function (): void {
    $shares = listings()->shares(page('shares'));

    expect($shares)->toHaveCount(89)
        ->and($shares[0]->symbol)->toBe('TLV')
        ->and($shares[0]->isin)->toBe('ROTLVAACNOR1')
        ->and($shares[0]->company)->toBe('BANCA TRANSILVANIA S.A.')
        ->and($shares[0]->price)->toBe('37.0000')
        ->and($shares[0]->changePercent)->toBe('1.09')
        ->and($shares[0]->pricedAt?->format('Y-m-d H:i e'))->toBe('2026-11-09 15:59 UTC');
});

it('reads every listed bond from BVB’s real page, an untraded one without a price', function (): void {
    $bonds = listings()->bonds(page('bonds'));
    $fidelis = array_values(array_filter($bonds, fn (ListedBond $bond): bool => $bond->symbol === 'R2707B'))[0];

    expect($bonds)->toHaveCount(223)
        ->and($fidelis->isin)->toBe('ROV4289T5QU6')
        ->and($fidelis->pricePercent)->toBe('100.9000')
        ->and($fidelis->couponPercent)->toBe('8.2500')
        ->and($fidelis->currency)->toBe('RON')
        ->and($fidelis->maturity->format('Y-m-d'))->toBe('2027-07-16')
        ->and($fidelis->type)->toBe(BvbBondType::GOVERNMENT)
        ->and(array_filter($bonds, fn (ListedBond $bond): bool => $bond->pricePercent === null))->toHaveCount(27)
        ->and(array_unique(array_map(fn (ListedBond $bond): string => $bond->type->value, $bonds)))->toHaveCount(3);
});

it('reads a bond whose coupon BVB leaves empty as one without a coupon, and still refuses one not a number', function (): void {
    $row = '<td align="right">09.11.2026 17:27</td><td align="right">8,2500</td>';
    $bonds = listings()->bonds(str_replace($row, '<td align="right">09.11.2026 17:27</td><td align="right"></td>', page('bonds')));

    expect(array_values(array_filter($bonds, fn (ListedBond $bond): bool => $bond->symbol === 'R2707B'))[0]->couponPercent)->toBeNull()
        ->and(fn () => listings()->bonds(str_replace($row, '<td align="right">09.11.2026 17:27</td><td align="right">n/a</td>', page('bonds'))))
        ->toThrow(ParseFailure::class);
});

it('reads every dividend from BVB’s real page', function (): void {
    $dividends = listings()->dividends(page('dividends'));

    expect($dividends)->toHaveCount(140)
        ->and($dividends[1]->symbol)->toBe('H2O')
        ->and($dividends[1]->amountPerShare)->toBe('7.348365')
        ->and($dividends[1]->exDate->format('Y-m-d'))->toBe('2026-06-03')
        ->and($dividends[1]->recordDate->format('Y-m-d'))->toBe('2026-06-04')
        ->and($dividends[1]->paymentDate->format('Y-m-d'))->toBe('2026-06-25')
        ->and($dividends[1]->financialYear)->toBe(2025);
});

it('reads a list whose columns BVB has moved', function (): void {
    $html = bondPage();
    // The ISIN column moved to the end: the same row, the same values.
    $moved = str_replace(['<th>Simbol / ISIN</th>', '<td><a href="#"><b>R2899A</b></a><p>ROTLVAACNOR1</p></td>'], '', $html);
    $moved = str_replace(['</tr></thead>', '</tr></tbody>'], ['<th>Simbol / ISIN</th></tr></thead>', '<td><a href="#"><b>R2899A</b></a><p>ROTLVAACNOR1</p></td></tr></tbody>'], $moved);

    expect(listings()->bonds($moved)[0]->symbol)->toBe('R2899A');
});

it('fails the whole list, saying where, at a row that does not read', function (array $cells, string $message): void {
    expect(fn () => listings()->bonds(bondPage($cells)))->toThrow(ParseFailure::class, $message);
})->with([
    'an ISIN with a wrong check digit' => [[0 => '<a><b>R2899A</b></a><p>ROTLVAACNOR2</p>'], 'BVB bonds, row 1, ISIN: an ISIN whose check digit is wrong'],
    'a symbol with markup' => [[0 => '<a><b>R2899A<script>x</script></b></a><p>ROTLVAACNOR1</p>'], 'row 1, symbol: not a symbol'],
    'a malformed price' => [[2 => '100.9000'], 'row 1, Pret (%): not a decimal'],
    'an impossible maturity' => [[7 => '31.02.2027'], 'row 1, Data maturitatii: not a date'],
    'a type it does not know' => [[8 => 'Obligatiuni verzi'], 'row 1, Tip: a bond type this list does not know'],
    'not a currency' => [[6 => 'lei'], 'row 1, Moneda de emisiune: not a currency'],
    'an empty company' => [[1 => '   '], 'row 1, Societate: empty'],
]);

it('fails a list with a column renamed, and one with no rows', function (): void {
    expect(fn () => listings()->bonds(str_replace('<th>Tip</th>', '<th>Type</th>', bondPage())))->toThrow(ParseFailure::class, 'no table with the columns')
        ->and(fn () => listings()->bonds(preg_replace('#<tbody>.*</tbody>#s', '<tbody></tbody>', bondPage()) ?? ''))->toThrow(ParseFailure::class, 'the list is empty')
        ->and(fn () => listings()->shares(page('bonds')))->toThrow(ParseFailure::class, 'BVB shares: no table');
});
