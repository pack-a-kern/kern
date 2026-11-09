<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\InvestmentsConfigTest;

use Illuminate\Config\Repository;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Legislation\Config\OfficialSourceRegistry;
use RuntimeException;

/** @param array<string, mixed> $bvb what differs from a valid configuration */
function read(array $bvb = []): InvestmentsConfig
{
    $config = new Repository([
        'investments' => ['processing_timeout_seconds' => array_key_exists('processing_timeout_seconds', $bvb) ? $bvb['processing_timeout_seconds'] : '60', 'bvb' => [
            'shares_url' => 'https://www.bvb.ro/shares',
            'bonds_url' => 'https://www.bvb.ro/bonds',
            'dividends_url' => 'https://www.bvb.ro/dividends',
            'instrument_url' => 'https://www.bvb.ro/instrument?s={symbol}',
            'timezone' => 'Europe/Bucharest',
            ...$bvb,
        ]],
        'sources' => [
            'identify_as' => 'browser',
            'browser' => ['user_agent' => 'Mozilla/5.0 (Test)'],
            // The package's own entry, as its provider adds it.
            'entries' => require __DIR__ . '/../../config/sources.php',
        ],
    ]);

    return InvestmentsConfig::read($config, new OfficialSourceRegistry($config));
}

it('reads every address and builds an instrument page, its symbol encoded', function (): void {
    $bvb = read()->bvb;

    expect($bvb->sharesUrl)->toBe('https://www.bvb.ro/shares')
        ->and($bvb->bondsUrl)->toBe('https://www.bvb.ro/bonds')
        ->and($bvb->dividendsUrl)->toBe('https://www.bvb.ro/dividends')
        ->and($bvb->instrumentUrl('H2O'))->toBe('https://www.bvb.ro/instrument?s=H2O')
        ->and($bvb->instrumentUrl('A&B=1#x'))->toBe('https://www.bvb.ro/instrument?s=A%26B%3D1%23x');
});

it('fails naming the variable when an address is missing or not one to fetch', function (string $key, mixed $value, string $variable): void {
    expect(fn () => read([$key => $value]))->toThrow(RuntimeException::class, $variable);
})->with([
    'missing' => ['shares_url', null, 'INVESTMENTS_BVB_SHARES_URL'],
    'empty' => ['bonds_url', '', 'INVESTMENTS_BVB_BONDS_URL'],
    'plain http' => ['dividends_url', 'http://www.bvb.ro/dividends', 'INVESTMENTS_BVB_DIVIDENDS_URL'],
    'another host' => ['shares_url', 'https://bvb.ro.example.com/shares', 'INVESTMENTS_BVB_SHARES_URL'],
    'a host behind a user' => ['bonds_url', 'https://www.bvb.ro@example.com/bonds', 'INVESTMENTS_BVB_BONDS_URL'],
    'not text' => ['instrument_url', ['https://www.bvb.ro/instrument?s={symbol}'], 'INVESTMENTS_BVB_INSTRUMENT_URL'],
    'no time zone' => ['timezone', null, 'INVESTMENTS_BVB_TIMEZONE'],
    'not a time zone' => ['timezone', 'Bucharest', 'INVESTMENTS_BVB_TIMEZONE'],
    'no timeout' => ['processing_timeout_seconds', null, 'INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'],
    'a timeout of none' => ['processing_timeout_seconds', '0', 'INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'],
    'a timeout in words' => ['processing_timeout_seconds', 'a minute', 'INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'],
]);

it('fails when the instrument page does not say once where its symbol goes', function (string $url): void {
    expect(fn () => read(['instrument_url' => $url]))->toThrow(RuntimeException::class, 'INVESTMENTS_BVB_INSTRUMENT_URL');
})->with(['none' => ['https://www.bvb.ro/instrument'], 'twice' => ['https://www.bvb.ro/{symbol}?s={symbol}']]);
