<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\InvestmentsConfigTest;

use Illuminate\Config\Repository;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Enums\Programme;
use Kern\Legislation\Config\OfficialSourceRegistry;
use RuntimeException;

/** @param array<string, mixed> $changed config keys, dotted, and what each is set to instead */
function read(array $changed = []): InvestmentsConfig
{
    $config = new Repository([
        'investments' => require __DIR__ . '/../Fixtures/config.php',
        'sources' => [
            'identify_as' => 'browser',
            'browser' => ['user_agent' => 'Mozilla/5.0 (Test)'],
            // The package's own entry, as its provider adds it, and kern/legislation's MF.
            'entries' => [...array_replace_recursive(require __DIR__ . '/../../config/sources.php', ['BVB' => ['base_url' => 'https://www.bvb.ro']]), 'MF' => ['trust_tier' => 'C', 'allowed_hosts' => ['mfinante.gov.ro'], 'enabled' => true, 'priority' => 1]],
        ],
    ]);

    foreach ($changed as $key => $value) {
        // The base address is the BVB source entry's, not this package's configuration.
        $config->set($key === '__bvb_base' ? 'sources.entries.BVB.base_url' : "investments.{$key}", $value);
    }

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
    'missing' => ['bvb.shares_url', null, 'INVESTMENTS_BVB_SHARES_URL'],
    'empty' => ['bvb.bonds_url', '', 'INVESTMENTS_BVB_BONDS_URL'],
    'plain http' => ['bvb.dividends_url', 'http://www.bvb.ro/dividends', 'INVESTMENTS_BVB_DIVIDENDS_URL'],
    'another host' => ['bvb.shares_url', 'https://bvb.ro.example.com/shares', 'INVESTMENTS_BVB_SHARES_URL'],
    'a host behind a user' => ['bvb.bonds_url', 'https://www.bvb.ro@example.com/bonds', 'INVESTMENTS_BVB_BONDS_URL'],
    'not text' => ['bvb.instrument_url', ['https://www.bvb.ro/instrument?s={symbol}'], 'INVESTMENTS_BVB_INSTRUMENT_URL'],
    'no time zone' => ['bvb.timezone', null, 'INVESTMENTS_BVB_TIMEZONE'],
    'not a time zone' => ['bvb.timezone', 'Bucharest', 'INVESTMENTS_BVB_TIMEZONE'],
    'no base address' => ['__bvb_base', null, 'INVESTMENTS_BVB_BASE_URL'],
    'no timeout' => ['processing_timeout_seconds', null, 'INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'],
    'a timeout of none' => ['processing_timeout_seconds', '0', 'INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'],
    'a timeout in words' => ['processing_timeout_seconds', 'a minute', 'INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'],
]);

it('fails when the instrument page does not say once where its symbol goes', function (string $url): void {
    expect(fn () => read(['bvb.instrument_url' => $url]))->toThrow(RuntimeException::class, 'INVESTMENTS_BVB_INSTRUMENT_URL');
})->with(['none' => ['https://www.bvb.ro/instrument'], 'twice' => ['https://www.bvb.ro/{symbol}?s={symbol}']]);

it('tells a programme’s order from a law that only mentions it', function (): void {
    $tezaur = read()->orders(Programme::TEZAUR);

    expect($tezaur->isTitled('ORDIN privind prospectul de emisiune … în cadrul Programului Tezaur, în perioada noiembrie 2026'))->toBeTrue()
        // As order 3.957/2022 writes it.
        ->and($tezaur->isTitled('ORDIN privind prospectul de emisiune … în cadrul Programului TEZAUR, aferent lunii noiembrie 2022'))->toBeTrue()
        ->and($tezaur->isTitled('LEGE privind bugetul de stat, care pomenește Programul Tezaur'))->toBeFalse();
});

it('finds each list’s link on the Ministry’s page', function (): void {
    $page = '<a href="/static/TEZAUR2018-2026_06102026.xlsx">Tezaur</a> <a href="/static/FIDELIS2020-2026_12102026.xlsx">Fidelis</a>';

    expect(read()->subscriptions->linkIn($page, Programme::FIDELIS))->toBe('https://mfinante.gov.ro/static/FIDELIS2020-2026_12102026.xlsx')
        ->and(read()->subscriptions->linkIn('<a href="//elsewhere.example/TEZAUR.xlsx">x</a>', Programme::TEZAUR))->toBeNull()
        ->and(read()->subscriptions->linkIn('<p>moved</p>', Programme::TEZAUR))->toBeNull();
});

it('fails naming the variable when an order or list setting is missing or wrong', function (string $key, mixed $value, string $variable): void {
    expect(fn () => read([$key => $value]))->toThrow(RuntimeException::class, $variable);
})->with([
    'no act type' => ['orders.act_type', null, 'INVESTMENTS_ORDERS_ACT_TYPE'],
    'a pattern that does not compile' => ['orders.title_patterns.TEZAUR', '/Tezaur(/u', 'INVESTMENTS_TEZAUR_TITLE_PATTERN'],
    'no pattern' => ['orders.title_patterns.FIDELIS', null, 'INVESTMENTS_FIDELIS_TITLE_PATTERN'],
    'a page off its host' => ['subscriptions.page_url', 'https://example.com/lists', 'INVESTMENTS_SUBSCRIPTIONS_PAGE_URL'],
    'no file pattern' => ['subscriptions.file_patterns.FIDELIS', null, 'INVESTMENTS_SUBSCRIPTIONS_FIDELIS_FILE'],
    'a chunk of nothing' => ['write_chunk', '0', 'INVESTMENTS_WRITE_CHUNK'],
]);
