<?php

declare(strict_types=1);

namespace Kern\Platform\Tests\Unit\KeyManagement\SubjectDekServiceTest;

use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;

/**
 * Counts unwrap calls so the "cached for the duration of one use" behaviour is observable
 * without reaching into SubjectDekService's private cache.
 */
final class CountingKeyManagementService implements KeyManagementService
{
    public int $unwrapCalls = 0;

    public int $wrapCalls = 0;

    public function __construct(private readonly InMemoryKeyManagementService $inner)
    {
    }

    public function wrapDataKey(string $plaintextDek): WrappedDataKey
    {
        $this->wrapCalls++;

        return $this->inner->wrapDataKey($plaintextDek);
    }

    public function unwrapDataKey(WrappedDataKey $wrapped): string
    {
        $this->unwrapCalls++;

        return $this->inner->unwrapDataKey($wrapped);
    }

    public function currentKekVersion(): int
    {
        return $this->inner->currentKekVersion();
    }

    public function rotateKek(): int
    {
        return $this->inner->rotateKek();
    }
}

it('generates a random 256-bit DEK on first use and persists only the wrapped form', function (): void {
    $repository = new InMemoryDekRepository();
    $service = new SubjectDekService(new InMemoryKeyManagementService(), $repository);

    $dek = $service->plaintextDekFor('subject-1');
    $stored = $repository->find('subject-1');

    expect(strlen($dek))->toBe(32)
        ->and($stored)->not->toBeNull();

    /** @var WrappedDataKey $stored */
    expect($stored->ciphertext)->not->toContain($dek);
});

it('returns the same plaintext DEK for the same subject on a second call', function (): void {
    $service = new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository());

    $first = $service->plaintextDekFor('subject-1');
    $second = $service->plaintextDekFor('subject-1');

    expect($second)->toBe($first);
});

it('gives different subjects different DEKs', function (): void {
    $service = new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository());

    expect($service->plaintextDekFor('subject-1'))->not->toBe($service->plaintextDekFor('subject-2'));
});

it('caches the unwrapped DEK: a repeated call does not unwrap again', function (): void {
    $counting = new CountingKeyManagementService(new InMemoryKeyManagementService());
    $service = new SubjectDekService($counting, new InMemoryDekRepository());

    $service->plaintextDekFor('subject-1'); // first use: wraps, no unwrap needed
    expect($counting->unwrapCalls)->toBe(0);

    $service->plaintextDekFor('subject-1'); // cached: still no unwrap
    $service->plaintextDekFor('subject-1');
    expect($counting->unwrapCalls)->toBe(0)
        ->and($counting->wrapCalls)->toBe(1);
});

it('unwraps again for a subject whose wrapped DEK already existed (e.g. a fresh instance)', function (): void {
    $repository = new InMemoryDekRepository();
    $kms = new InMemoryKeyManagementService();

    $first = new SubjectDekService($kms, $repository);
    $original = $first->plaintextDekFor('subject-1');

    $counting = new CountingKeyManagementService($kms);
    $second = new SubjectDekService($counting, $repository);
    $rehydrated = $second->plaintextDekFor('subject-1');

    expect($rehydrated)->toBe($original)
        ->and($counting->unwrapCalls)->toBe(1);
});

it('forgetAll() drops the cache, forcing a fresh unwrap on next use', function (): void {
    $repository = new InMemoryDekRepository();
    $kms = new InMemoryKeyManagementService();
    $repository->save('subject-1', $kms->wrapDataKey(random_bytes(32)));

    $counting = new CountingKeyManagementService($kms);
    $service = new SubjectDekService($counting, $repository);

    $service->plaintextDekFor('subject-1');
    expect($counting->unwrapCalls)->toBe(1);

    $service->plaintextDekFor('subject-1');
    expect($counting->unwrapCalls)->toBe(1); // still cached

    $service->forgetAll();

    $service->plaintextDekFor('subject-1');
    expect($counting->unwrapCalls)->toBe(2);
});
