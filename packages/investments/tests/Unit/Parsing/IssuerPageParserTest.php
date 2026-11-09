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

it('refuses a page that is not an issuer’s', function (): void {
    expect(fn () => IssuerPageParser::parse(page('instrument-bond')))->toThrow(ParseFailure::class, 'neither a meetings calendar nor a shareholder structure')
        ->and(fn () => IssuerPageParser::parse('<html><body>Service unavailable</body></html>'))->toThrow(ParseFailure::class);
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
