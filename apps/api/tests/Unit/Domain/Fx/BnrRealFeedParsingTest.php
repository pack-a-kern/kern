<?php

declare(strict_types=1);

use Kern\TaxRomania\Fx\Parsing\BnrXmlParser;

/**
 * Parses a REAL BNR daily feed, captured verbatim from https://curs.bnr.ro/nbrfxrates.xml on
 * 2026-09-08 (fixture: captured-daily-2026-09-07.xml — genuinely captured, unlike the neighbouring
 * `synthetic-*.xml` files).
 *
 * The other parser tests use synthetic fixtures, which prove the parser handles the format we
 * *believe* BNR publishes. This one proves it handles what BNR actually publishes — including the
 * multiplier trap, which turns out to be real: five currencies are quoted per 100 units.
 * Getting that wrong scales a conversion by 100 silently.
 */
it('parses the real BNR daily feed captured from curs.bnr.ro', function (): void {
    $xml = file_get_contents(__DIR__ . '/../../../Fixtures/Fx/Bnr/captured-daily-2026-09-07.xml');
    expect($xml)->toBeString();

    $days = (new BnrXmlParser())->parse((string) $xml);

    expect($days)->toHaveCount(1)
        ->and($days[0]->rates)->not->toBeEmpty();
});

it('preserves the per-100 multiplier that real BNR data uses for JPY, HUF, ISK, IDR and KRW', function (): void {
    $xml = (string) file_get_contents(__DIR__ . '/../../../Fixtures/Fx/Bnr/captured-daily-2026-09-07.xml');
    $days = (new BnrXmlParser())->parse($xml);

    $byCurrency = [];
    foreach ($days[0]->rates as $rate) {
        $byCurrency[$rate->currencyCode] = $rate;
    }

    // Verified against the captured file: these five carry multiplier="100".
    foreach (['JPY', 'HUF', 'ISK', 'IDR', 'KRW'] as $code) {
        expect($byCurrency)->toHaveKey($code)
            ->and($byCurrency[$code]->multiplier)->toBe(100);
    }

    // EUR is quoted per single unit; if this ever became 100 silently, conversions would be 100x off.
    expect($byCurrency)->toHaveKey('EUR')
        ->and($byCurrency['EUR']->multiplier)->toBe(1);
});
