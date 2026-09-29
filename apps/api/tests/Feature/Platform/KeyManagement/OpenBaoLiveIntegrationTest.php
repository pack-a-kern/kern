<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\KeyManagement\OpenBaoLiveIntegrationTest;

use Illuminate\Http\Client\Factory as HttpFactory;
use Kern\Platform\KeyManagement\OpenBaoTransitKeyManagementService;

/**
 * Proves `OpenBaoTransitKeyManagementService` against a real, running dev-mode OpenBao
 * (docker/openbao/entrypoint.sh) — not a fake, not a mock. Skipped unless both `OPENBAO_TEST_ADDR`
 * and `OPENBAO_TEST_TOKEN` are set, since CI and `make test` do not start OpenBao alongside the
 * PHP test image. See docs/handoff/005-crypto-core.md "How to verify" for the exact commands
 * that were used to run this for real against `docker compose`'s `openbao` service.
 *
 * Uses a dedicated key name (`OPENBAO_TEST_KEK_NAME`, default `kern-crypto-core-test-kek`) —
 * never the real `tax-subject-kek` — so this test's `rotateKek()` call cannot affect the key
 * anything else in the stack depends on.
 */
function liveOpenBaoUnavailable(): bool
{
    return getenv('OPENBAO_TEST_ADDR') === false || getenv('OPENBAO_TEST_TOKEN') === false;
}

function liveService(): OpenBaoTransitKeyManagementService
{
    $address = getenv('OPENBAO_TEST_ADDR');
    $token = getenv('OPENBAO_TEST_TOKEN');
    $keyName = getenv('OPENBAO_TEST_KEK_NAME');

    return new OpenBaoTransitKeyManagementService(
        app(HttpFactory::class),
        is_string($address) ? $address : '',
        is_string($token) ? $token : '',
        'transit',
        is_string($keyName) && $keyName !== '' ? $keyName : 'kern-crypto-core-test-kek',
        5,
    );
}

it('wraps and unwraps a real DEK through a live OpenBao transit engine', function (): void {
    $service = liveService();
    $dek = random_bytes(32);

    $wrapped = $service->wrapDataKey($dek);

    expect($wrapped->ciphertext)->toStartWith('vault:v');
    expect($service->unwrapDataKey($wrapped))->toBe($dek);
})->skip(fn (): bool => liveOpenBaoUnavailable(), 'Set OPENBAO_TEST_ADDR and OPENBAO_TEST_TOKEN to run against a live OpenBao.');

it('rotates the live KEK: old wraps still unwrap, new wraps carry the new version', function (): void {
    $service = liveService();
    $dek = random_bytes(32);

    $wrappedBefore = $service->wrapDataKey($dek);
    $versionBefore = $wrappedBefore->kekVersion;

    $newVersion = $service->rotateKek();

    expect($newVersion)->toBeGreaterThan($versionBefore)
        ->and($service->unwrapDataKey($wrappedBefore))->toBe($dek);

    $wrappedAfter = $service->wrapDataKey(random_bytes(32));
    expect($wrappedAfter->kekVersion)->toBe($newVersion);
})->skip(fn (): bool => liveOpenBaoUnavailable(), 'Set OPENBAO_TEST_ADDR and OPENBAO_TEST_TOKEN to run against a live OpenBao.');
