<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Tests\Unit\Fx\BnrXmlParserTest;

use Kern\TaxRomania\Fx\DataTransferObjects\ParsedFxRate;
use Kern\TaxRomania\Fx\Parsing\BnrXmlParser;
use Kern\TaxRomania\Fx\Parsing\Exceptions\InvalidBnrXmlException;
use RuntimeException;

/** The fixture's *contents* — Pest's own `fixture()` gives the path. */
function bnrFixture(string $name): string
{
    $path = packageFixture('Fx/Bnr/' . $name);
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Missing test fixture: {$name}");
    }

    return $contents;
}

it('parses a single-day synthetic fixture into one ParsedFxDay with every currency', function (): void {
    $days = (new BnrXmlParser())->parse(bnrFixture('synthetic-daily.xml'));

    expect($days)->toHaveCount(1);

    $day = $days[0];
    expect($day->date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($day->rates)->toHaveCount(3);

    $byCurrency = [];
    foreach ($day->rates as $rate) {
        $byCurrency[$rate->currencyCode] = $rate;
    }

    expect($byCurrency['EUR']->multiplier)->toBe(1)
        ->and($byCurrency['EUR']->rateDecimal->toString())->toBe('4.976400')
        ->and($byCurrency['USD']->multiplier)->toBe(1)
        ->and($byCurrency['USD']->rateDecimal->toString())->toBe('4.582100');
});

it('correctly parses a currency quoted with a multiplier other than 1', function (): void {
    $days = (new BnrXmlParser())->parse(bnrFixture('synthetic-daily.xml'));

    $huf = collect($days[0]->rates)->firstWhere('currencyCode', 'HUF');

    expect($huf)->not->toBeNull();
    assert($huf instanceof ParsedFxRate);

    expect($huf->multiplier)->toBe(100)
        ->and($huf->rateDecimal->toString())->toBe('1279.990000');
});

it('parses a multi-day archive into one ParsedFxDay per Cube, skipping non-banking days entirely', function (): void {
    $days = (new BnrXmlParser())->parse(bnrFixture('synthetic-yearly.xml'));

    $dates = array_map(static fn ($day) => $day->date->format('Y-m-d'), $days);

    // 2026-01-17/18 (Sat/Sun) are absent — no row was published, not a zero/blank one.
    expect($dates)->toBe(['2026-01-14', '2026-01-15', '2026-01-16', '2026-01-19', '2026-01-20']);
});

it('throws on malformed XML', function (): void {
    (new BnrXmlParser())->parse(bnrFixture('malformed.xml'));
})->throws(InvalidBnrXmlException::class);

it('throws when the document has no Cube elements', function (): void {
    (new BnrXmlParser())->parse('<DataSet><Body><NotACube/></Body></DataSet>');
})->throws(InvalidBnrXmlException::class);

it('throws on an invalid Cube date', function (): void {
    (new BnrXmlParser())->parse('<DataSet><Body><Cube date="2026-13-40"><Rate currency="EUR">4.9</Rate></Cube></Body></DataSet>');
})->throws(InvalidBnrXmlException::class);

it('throws on a duplicate Cube date within the same document', function (): void {
    $xml = <<<'XML'
        <DataSet><Body>
            <Cube date="2026-01-15"><Rate currency="EUR">4.9</Rate></Cube>
            <Cube date="2026-01-15"><Rate currency="EUR">5.0</Rate></Cube>
        </Body></DataSet>
        XML;

    (new BnrXmlParser())->parse($xml);
})->throws(InvalidBnrXmlException::class);

it('throws on an invalid currency code', function (): void {
    (new BnrXmlParser())->parse('<DataSet><Body><Cube date="2026-01-15"><Rate currency="EU1">4.9</Rate></Cube></Body></DataSet>');
})->throws(InvalidBnrXmlException::class);

it('throws on a duplicate currency within the same day', function (): void {
    $xml = <<<'XML'
        <DataSet><Body>
            <Cube date="2026-01-15">
                <Rate currency="EUR">4.9</Rate>
                <Rate currency="EUR">5.0</Rate>
            </Cube>
        </Body></DataSet>
        XML;

    (new BnrXmlParser())->parse($xml);
})->throws(InvalidBnrXmlException::class);

it('throws on a non-numeric rate value', function (): void {
    (new BnrXmlParser())->parse('<DataSet><Body><Cube date="2026-01-15"><Rate currency="EUR">not-a-number</Rate></Cube></Body></DataSet>');
})->throws(InvalidBnrXmlException::class);

it('throws on a zero or negative multiplier', function (): void {
    (new BnrXmlParser())->parse('<DataSet><Body><Cube date="2026-01-15"><Rate currency="EUR" multiplier="0">4.9</Rate></Cube></Body></DataSet>');
})->throws(InvalidBnrXmlException::class);

it('throws when a Cube has no Rate elements', function (): void {
    (new BnrXmlParser())->parse('<DataSet><Body><Cube date="2026-01-15"></Cube></Body></DataSet>');
})->throws(InvalidBnrXmlException::class);

it('is namespace-agnostic, parsing identically with or without a default XML namespace', function (): void {
    $withoutNamespace = '<DataSet><Body><Cube date="2026-01-15"><Rate currency="EUR">4.9764</Rate></Cube></Body></DataSet>';
    $withNamespace = '<DataSet xmlns="http://www.bnr.ro/xsd"><Body><Cube date="2026-01-15"><Rate currency="EUR">4.9764</Rate></Cube></Body></DataSet>';

    $parser = new BnrXmlParser();

    $a = $parser->parse($withoutNamespace);
    $b = $parser->parse($withNamespace);

    expect($a[0]->rates[0]->rateDecimal->toString())->toBe($b[0]->rates[0]->rateDecimal->toString());
});
