<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\TaxKnowledge\OfficialSourceRegistryTest;

use App\Domain\TaxKnowledge\Config\OfficialSourceRegistry;
use App\Domain\TaxKnowledge\Enums\TrustTier;
use App\Domain\TaxKnowledge\Exceptions\UnknownSourceException;
use Illuminate\Config\Repository;
use RuntimeException;

/**
 * @param array<string, array<string, mixed>> $entries
 */
function registryWith(array $entries): OfficialSourceRegistry
{
    return new OfficialSourceRegistry(new Repository(['sources' => ['entries' => $entries]]));
}

it('resolves a fully-specified entry into a typed SourceDefinition', function (): void {
    $registry = registryWith([
        'BNR' => [
            'trust_tier' => 'D',
            'base_url' => 'https://www.bnr.ro',
            'allowed_hosts' => ['bnr.ro', 'WWW.BNR.RO'],
            'user_agent' => 'TestAgent/1.0',
            'enabled' => true,
            'priority' => 1,
        ],
    ]);

    $definition = $registry->find('BNR');

    expect($definition->code)->toBe('BNR')
        ->and($definition->trustTier)->toBe(TrustTier::D)
        ->and($definition->baseUrl)->toBe('https://www.bnr.ro')
        ->and($definition->userAgent)->toBe('TestAgent/1.0')
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
            'user_agent' => 'TestAgent/1.0',
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
            'user_agent' => 'TestAgent/1.0',
            'enabled' => true,
            'priority' => 1,
        ],
    ])->find('BNR');

    expect($definition->baseUrl)->toBeNull();
});

it('rejects an entry missing a required user_agent', function (): void {
    registryWith([
        'BNR' => [
            'trust_tier' => 'D',
            'base_url' => null,
            'allowed_hosts' => ['bnr.ro'],
            'enabled' => true,
            'priority' => 1,
        ],
    ])->find('BNR');
})->throws(RuntimeException::class);

it('rejects an entry with an empty allowed_hosts list', function (): void {
    registryWith([
        'BNR' => [
            'trust_tier' => 'D',
            'base_url' => null,
            'allowed_hosts' => [],
            'user_agent' => 'TestAgent/1.0',
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
            'user_agent' => 'TestAgent/1.0',
            'enabled' => true,
            'priority' => 1,
        ],
    ])->find('BNR');
})->throws(RuntimeException::class);

it('lists every configured entry via all()', function (): void {
    $registry = registryWith([
        'BNR' => ['trust_tier' => 'D', 'base_url' => null, 'allowed_hosts' => ['bnr.ro'], 'user_agent' => 'A', 'enabled' => true, 'priority' => 1],
        'ANAF' => ['trust_tier' => 'B', 'base_url' => null, 'allowed_hosts' => ['anaf.ro'], 'user_agent' => 'A', 'enabled' => true, 'priority' => 1],
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
            'user_agent' => 'TestAgent/1.0',
            'enabled' => true,
            'priority' => 0,
        ],
    ])->find('BNR');
})->throws(RuntimeException::class);

it('reads the TLS curves a source declares, and leaves the rest on the library default', function (): void {
    $registry = registryWith([
        'BNR' => ['trust_tier' => 'D', 'base_url' => null, 'allowed_hosts' => ['bnr.ro'], 'user_agent' => 'A', 'enabled' => true, 'priority' => 1, 'tls_curves' => 'X25519:P-256'],
        'ANAF' => ['trust_tier' => 'B', 'base_url' => null, 'allowed_hosts' => ['anaf.ro'], 'user_agent' => 'A', 'enabled' => true, 'priority' => 1],
    ]);

    expect($registry->find('BNR')->tlsCurves)->toBe('X25519:P-256')
        ->and($registry->find('ANAF')->tlsCurves)->toBeNull();
});

it('rejects an empty tls_curves rather than sending curl an empty list', function (): void {
    registryWith([
        'BNR' => ['trust_tier' => 'D', 'base_url' => null, 'allowed_hosts' => ['bnr.ro'], 'user_agent' => 'A', 'enabled' => true, 'priority' => 1, 'tls_curves' => ''],
    ])->find('BNR');
})->throws(RuntimeException::class);
