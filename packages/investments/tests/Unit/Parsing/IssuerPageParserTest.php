<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\Parsing\IssuerPageParserTest;

use Kern\Investments\Parsing\Bvb\IssuerPageParser;
use Kern\Investments\Parsing\ParseFailure;

function page(string $name): string
{
    return (string) file_get_contents(__DIR__ . "/../../Fixtures/bvb/{$name}.html");
}

it('reads a company’s meetings and its shareholders from its real BVB page, the total left out', function (): void {
    $issuer = IssuerPageParser::parse(page('instrument-share'));

    expect($issuer->meetings)->toHaveCount(5)
        ->and($issuer->meetings[0]->date->format('Y-m-d'))->toBe('2026-01-27')
        ->and($issuer->meetings[0]->kind)->toBe('Adunarea Generala Extraordinara a Actionarilor')
        ->and($issuer->shareholders)->toHaveCount(3)
        ->and($issuer->shareholders[0]->shares)->toBe(360094390)
        ->and($issuer->shareholders[0]->percent)->toBe('80.0560')
        ->and($issuer->shareholdersAsOf?->format('Y-m-d'))->toBe('2025-12-31');
});

it('reads a company’s financial calendar and its figures from its real BVB page', function (): void {
    $issuer = IssuerPageParser::parse(page('instrument-share'));
    $figures = $issuer->figures;

    expect(array_map(fn ($event): array => [$event->date->format('Y-m-d'), $event->what], $issuer->financialCalendar))->toBe([
        ['2026-11-16', 'Rezultate financiare trimestrul III 2026'],
        ['2026-11-17', 'Teleconferinta – prezentare rezultate financiare trimestrul III'],
    ])
        ->and([$figures->marketCap, $figures->priceEarnings, $figures->priceBook, $figures->earningsPerShare, $figures->dividendYield])
        ->toBe(['84382961569.20', '19.38', '4.13', '9.68', '5.10'])
        ->and([$figures->lastDividend, $figures->lastDividendYear])->toBe(['9.571562', 2025])
        ->and([$figures->week52High, $figures->week52Low])->toBe(['221.0000', '118.9000'])
        ->and([$figures->sharesTotal, $figures->faceValue, $figures->shareCapital])->toBe([449802567, '10.0000', '4498025670.00'])
        ->and($figures->tradingSince?->format('Y-m-d'))->toBe('2023-07-12')
        ->and([$figures->segment, $figures->category])->toBe(['Principal', 'Premium']);
});

it('reads a figure the page leaves out as none, and fails one that is not a number', function (): void {
    $without = IssuerPageParser::parse(str_replace('<td>PER</td><td align="right" style="font-weight:bold;">19,38</td>', '', page('instrument-share')));

    expect($without->figures->priceEarnings)->toBeNull()
        ->and($without->figures->marketCap)->toBe('84382961569.20')
        ->and(fn () => IssuerPageParser::parse(str_replace('>19,38<', '>n/a<', page('instrument-share'))))->toThrow(ParseFailure::class, 'PER');
});

it('reads a bond’s yield, coupon, issue and best bid and ask from its real BVB page, with no meetings or shareholders', function (): void {
    $bond = IssuerPageParser::parse(page('instrument-bond'));
    $figures = $bond->figures;

    expect([$bond->meetings, $bond->shareholders])->toBe([[], []])
        ->and([$figures->bid, $figures->ask])->toBe(['100.4500', '100.9999'])
        ->and([$figures->couponRate, $figures->yieldToMaturity])->toBe(['8.2500', '6.75'])
        ->and([$figures->unitsIssued, $figures->issueValue, $figures->faceValue])->toBe([990835, '99083500', '100.0000'])
        ->and([$figures->week52High, $figures->sharesTotal, $figures->marketCap])->toBe(['102.9600', null, null])
        ->and($figures->tradingSince?->format('Y-m-d'))->toBe('2025-07-17');
});

it('reads a figure the page shows as a dash as none', function (): void {
    $figures = IssuerPageParser::parse(str_replace('>6,75%<', '>-<', page('instrument-bond')))->figures;

    expect($figures->yieldToMaturity)->toBeNull()
        ->and($figures->couponRate)->toBe('8.2500');
});

it('refuses a page that is not an instrument’s', function (): void {
    expect(fn () => IssuerPageParser::parse('<html><body>Service unavailable</body></html>'))
        ->toThrow(ParseFailure::class, 'neither a meetings calendar, a shareholder structure nor an issue');
});

it('reads an empty calendar as no meetings, and fails a meeting dated a day that does not exist', function (): void {
    $calendar = fn (string $rows): string => '<div><h2>Calendar AGA</h2><table><thead><tr><th>&nbsp;</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

    expect(IssuerPageParser::parse($calendar(''))->meetings)->toBe([])
        ->and(fn () => IssuerPageParser::parse($calendar('<tr><td><b>31 februarie 2026</b> Adunarea Generala</td></tr>')))
        ->toThrow(ParseFailure::class, 'Calendar AGA, row 1: not a date');
});

it('fails a shareholder line whose shares are not a whole number', function (): void {
    $html = '<div><h2>Structura actionariat</h2><table><tr><td><table><tr><th>Actionar</th><th>Actiuni</th><th>Procent</th></tr>'
        . '<tr><td>EXAMPLE HOLDER</td><td>12,5</td><td>10 %</td></tr></table></td></tr></table></div>';

    expect(fn () => IssuerPageParser::parse($html))->toThrow(ParseFailure::class, 'Structura actionariat, row 1: not a whole number');
});
