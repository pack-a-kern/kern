<?php

declare(strict_types=1);

namespace Kern\Platform\Tests\Unit\Crypto\BlindIndex\BlindIndexHasherTest;

use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use Kern\Platform\Testing\InMemoryKeyManagementService;

it('is deterministic for the same normalized value and key version', function (): void {
    $hasher = new BlindIndexHasher(new InMemoryKeyManagementService());

    $value = Normalizer::cnp('1 900101 234567');

    expect($hasher->compute($value))->toBe($hasher->compute($value));
});

it('produces a different index for a different normalized value', function (): void {
    $hasher = new BlindIndexHasher(new InMemoryKeyManagementService());

    expect($hasher->compute('1900101234567'))->not->toBe($hasher->compute('1900101234568'));
});

it('is not a bare hash — two different keys produce two different indexes for the same value', function (): void {
    $indexA = (new BlindIndexHasher(new InMemoryKeyManagementService()))->compute('1900101234567');
    $indexB = (new BlindIndexHasher(new InMemoryKeyManagementService()))->compute('1900101234567');

    expect($indexA)->not->toBe($indexB)
        ->and($indexA)->not->toBe(hash('sha256', '1900101234567'));
});

it('computes a batch in order, matching one at a time', function (): void {
    // An import indexes every row in one request; that must not be a different index from the one a
    // single lookup computes, or a row imported in a batch could never be found again.
    $hasher = new BlindIndexHasher(new InMemoryKeyManagementService());

    expect($hasher->computeMany(['a', 'b', 'c']))
        ->toBe([$hasher->compute('a'), $hasher->compute('b'), $hasher->compute('c')]);
});

it('computes under the new version once the key is rotated', function (): void {
    $kms = new InMemoryKeyManagementService();
    $hasher = new BlindIndexHasher($kms);
    $before = $hasher->compute('value');

    $kms->rotateBlindIndexKey();

    expect($hasher->compute('value'))->not->toBe($before)
        ->and($hasher->currentVersion())->toBe(2);
});


/** The in-memory key manager, counting how many times it was asked for blind indexes. */
function countingKms(): KeyManagementService
{
    return new class () implements KeyManagementService {
        public int $blindIndexRequests = 0;

        private InMemoryKeyManagementService $inner;

        public function __construct()
        {
            $this->inner = new InMemoryKeyManagementService();
        }

        public function wrapDataKey(string $plaintextDek): WrappedDataKey
        {
            return $this->inner->wrapDataKey($plaintextDek);
        }

        public function unwrapDataKey(WrappedDataKey $wrapped): string
        {
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

        public function accessSecondsRemaining(): ?int
        {
            return $this->inner->accessSecondsRemaining();
        }

        public function blindIndexes(array $normalizedValues): array
        {
            $this->blindIndexRequests++;

            return $this->inner->blindIndexes($normalizedValues);
        }

        public function currentBlindIndexKeyVersion(): int
        {
            return $this->inner->currentBlindIndexKeyVersion();
        }
    };
}

it('keeps the gaps where a value has nothing to index, and asks the key manager once', function (): void {
    // Every batch caller — a statement's fingerprints, its counterparties — has rows with nothing to
    // index. They must come back as null in their place, and the rest in one request, not one each.
    $kms = countingKms();
    $hasher = new BlindIndexHasher($kms);

    $indexes = $hasher->computeEach(['a', null, 'b', null]);

    expect($kms->blindIndexRequests)->toBe(1)
        ->and($indexes[1])->toBeNull()
        ->and($indexes[3])->toBeNull()
        ->and([$indexes[0], $indexes[2]])->toBe($hasher->computeMany(['a', 'b']));
});
