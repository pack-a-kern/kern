<?php

declare(strict_types=1);

namespace Kern\Legislation\Tests\Unit\OfficialSourceRegistryTest;

use Illuminate\Config\Repository;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\Enums\TrustTier;
use Kern\Legislation\Exceptions\UnknownSourceException;
use RuntimeException;

const BROWSER = 'Mozilla/5.0 (Test) Chrome/128.0.0.0';

/**
 * @param array<string, array<string, mixed>> $entries
 * @param array<string, mixed> $sources what the installation sets beside the entries
 */
function registryWith(array $entries, array $sources = []): OfficialSourceRegistry
{
    return new OfficialSourceRegistry(new Repository(['sources' => [
        'identify_as' => 'browser',
        'browser' => ['user_agent' => BROWSER],
        'crawler' => ['name' => 'Kern-Test-Crawler/1.0', 'contact' => 'ops@example.test'],
        ...$sources,
        'entries' => $entries,
    ]]));
}

/** @return array<string, mixed> */
function bnr(array $overrides = []): array
{
    return ['trust_tier' => 'D', 'allowed_hosts' => ['bnr.ro'], 'enabled' => true, 'priority' => 1, ...$overrides];
}

it('resolves a fully-specified entry into a typed SourceDefinition', function (): void {
    $registry = registryWith([
        'BNR' => [
            'trust_tier' => 'D',
            'base_url' => 'https://www.bnr.ro',
            'allowed_hosts' => ['bnr.ro', 'WWW.BNR.RO'],
            'enabled' => true,
            'priority' => 1,
        ],
    ]);

    $definition = $registry->find('BNR');

    expect($definition->code)->toBe('BNR')
        ->and($definition->trustTier)->toBe(TrustTier::D)
        ->and($definition->baseUrl)->toBe('https://www.bnr.ro')
        ->and($definition->userAgent)->toBe(BROWSER)
        ->and($definition->enabled)->toBeTrue()
        // allowed_hosts is normalised to lower-case so host comparison is case-insensitive.
        ->and($definition->allowedHosts)->toBe(['bnr.ro', 'www.bnr.ro']);
});

it('is case-insensitive when checking whether a host is allowed', function (): void {
    $registry = registryWith([
        'BNR' => [
            'trust_tier' => 'D',
            'base_url' => null,
            'allowed_hosts' => ['bnr.ro'],
            'enabled' => true,
            'priority' => 1,
        ],
    ]);

    $definition = $registry->find('BNR');

    expect($definition->allowsHost('bnr.ro'))->toBeTrue()
        ->and($definition->allowsHost('BNR.RO'))->toBeTrue()
        ->and($definition->allowsHost('evil.example'))->toBeFalse();
});

it('throws for a code with no config entry', function (): void {
    registryWith([])->find('NOPE');
})->throws(UnknownSourceException::class);

it('accepts an entry with base_url omitted, since it is the only optional field', function (): void {
    $definition = registryWith([
        'BNR' => [
            'trust_tier' => 'D',
            'allowed_hosts' => ['bnr.ro'],
            'enabled' => true,
            'priority' => 1,
        ],
    ])->find('BNR');

    expect($definition->baseUrl)->toBeNull();
});

it('sends a source the browser User-Agent when the installation goes out as a browser', function (): void {
    expect(registryWith(['BNR' => bnr()])->find('BNR')->userAgent)->toBe(BROWSER);
});

it('sends the crawler with its contact to a source mapped to it, and the browser to the rest', function (): void {
    $registry = registryWith(['BNR' => bnr(['identify_as' => 'crawler']), 'ANAF' => bnr(['allowed_hosts' => ['anaf.ro']])]);

    expect($registry->find('BNR')->userAgent)->toBe('Kern-Test-Crawler/1.0 (contact: ops@example.test)')
        ->and($registry->find('ANAF')->userAgent)->toBe(BROWSER);
});

it('sends the browser to a source mapped to it when the installation goes out as the crawler', function (): void {
    $registry = registryWith(['BNR' => bnr(['identify_as' => 'browser']), 'ANAF' => bnr()], ['identify_as' => 'crawler']);

    expect($registry->find('BNR')->userAgent)->toBe(BROWSER)
        ->and($registry->find('ANAF')->userAgent)->toStartWith('Kern-Test-Crawler/1.0');
});

it('fails, naming the variable, when nothing says who a source goes out as', function (mixed $identity): void {
    expect(fn () => registryWith(['BNR' => bnr()], ['identify_as' => $identity])->find('BNR'))
        ->toThrow(RuntimeException::class, 'SOURCES_IDENTIFY_AS');
})->with(['unset' => [null], 'not an identity' => ['robot']]);

it('fails, naming the variable, when the browser has no User-Agent a header can carry', function (mixed $userAgent): void {
    expect(fn () => registryWith(['BNR' => bnr()], ['browser' => ['user_agent' => $userAgent]])->find('BNR'))
        ->toThrow(RuntimeException::class, 'SOURCES_BROWSER_USER_AGENT');
})->with(['unset' => [null], 'empty' => [''], 'a second header' => ["Mozilla/5.0\r\nX-Injected: yes"]]);

it('rejects an entry with an empty allowed_hosts list', function (): void {
    registryWith([
        'BNR' => [
            'trust_tier' => 'D',
            'base_url' => null,
            'allowed_hosts' => [],
            'enabled' => true,
            'priority' => 1,
        ],
    ])->find('BNR');
})->throws(RuntimeException::class);

it('rejects an entry with an invalid trust tier', function (): void {
    registryWith([
        'BNR' => [
            'trust_tier' => 'Z',
            'base_url' => null,
            'allowed_hosts' => ['bnr.ro'],
            'enabled' => true,
            'priority' => 1,
        ],
    ])->find('BNR');
})->throws(RuntimeException::class);

it('lists every configured entry via all()', function (): void {
    $registry = registryWith([
        'BNR' => ['trust_tier' => 'D', 'base_url' => null, 'allowed_hosts' => ['bnr.ro'], 'enabled' => true, 'priority' => 1],
        'ANAF' => ['trust_tier' => 'B', 'base_url' => null, 'allowed_hosts' => ['anaf.ro'], 'enabled' => true, 'priority' => 1],
    ]);

    $codes = array_map(static fn ($definition) => $definition->code, $registry->all());
    sort($codes);

    expect($codes)->toBe(['ANAF', 'BNR']);
});

it('rejects an entry without a priority of 1 or more', function (): void {
    registryWith([
        'BNR' => [
            'trust_tier' => 'D',
            'base_url' => null,
            'allowed_hosts' => ['bnr.ro'],
            'enabled' => true,
            'priority' => 0,
        ],
    ])->find('BNR');
})->throws(RuntimeException::class);

it('reads the TLS curves a source declares, and leaves the rest on the library default', function (): void {
    $registry = registryWith([
        'BNR' => ['trust_tier' => 'D', 'base_url' => null, 'allowed_hosts' => ['bnr.ro'], 'enabled' => true, 'priority' => 1, 'tls_curves' => 'X25519:P-256'],
        'ANAF' => ['trust_tier' => 'B', 'base_url' => null, 'allowed_hosts' => ['anaf.ro'], 'enabled' => true, 'priority' => 1],
    ]);

    expect($registry->find('BNR')->tlsCurves)->toBe('X25519:P-256')
        ->and($registry->find('ANAF')->tlsCurves)->toBeNull();
});

it('rejects an empty tls_curves rather than sending curl an empty list', function (): void {
    registryWith([
        'BNR' => ['trust_tier' => 'D', 'base_url' => null, 'allowed_hosts' => ['bnr.ro'], 'enabled' => true, 'priority' => 1, 'tls_curves' => ''],
    ])->find('BNR');
})->throws(RuntimeException::class);
