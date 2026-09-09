<?php

declare(strict_types=1);

namespace Tests\Feature\Support\KeyManagement\OpenBaoTransitKeyManagementServiceTest;

use App\Support\KeyManagement\Exceptions\KeyManagementException;
use App\Support\KeyManagement\OpenBaoTransitKeyManagementService;
use App\Support\KeyManagement\ValueObjects\WrappedDataKey;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Exercises the OpenBao transit adapter's request construction and response parsing against a
 * faked HTTP layer (`Http::fake()`), so these run in every CI invocation without a live OpenBao.
 * `tests/Feature/Support/KeyManagement/OpenBaoLiveIntegrationTest.php` separately proves the same
 * class against a real dev-mode OpenBao, guarded behind an env var since CI does not start one.
 */
function makeService(): OpenBaoTransitKeyManagementService
{
    return new OpenBaoTransitKeyManagementService(
        app(HttpFactory::class),
        'http://openbao.test',
        'test-token',
        'transit',
        'tax-subject-kek',
        5,
    );
}

it('wraps a plaintext DEK via the transit encrypt endpoint', function (): void {
    Http::fake([
        'openbao.test/v1/transit/encrypt/tax-subject-kek' => Http::response([
            'data' => ['ciphertext' => 'vault:v1:abc123'],
        ]),
    ]);

    $wrapped = makeService()->wrapDataKey(random_bytes(32));

    expect($wrapped->ciphertext)->toBe('vault:v1:abc123')
        ->and($wrapped->kekVersion)->toBe(1);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'http://openbao.test/v1/transit/encrypt/tax-subject-kek'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && is_string($request['plaintext']);
    });
});

it('unwraps a wrapped DEK via the transit decrypt endpoint', function (): void {
    $plaintext = random_bytes(32);

    Http::fake([
        'openbao.test/v1/transit/decrypt/tax-subject-kek' => Http::response([
            'data' => ['plaintext' => base64_encode($plaintext)],
        ]),
    ]);

    $result = makeService()->unwrapDataKey(new WrappedDataKey('vault:v1:abc123', 1));

    expect($result)->toBe($plaintext);
});

it('reads the current KEK version via the transit key-info endpoint', function (): void {
    Http::fake([
        'openbao.test/v1/transit/keys/tax-subject-kek' => Http::response([
            'data' => ['latest_version' => 3],
        ]),
    ]);

    expect(makeService()->currentKekVersion())->toBe(3);
});

it('rotates the KEK and returns the new version', function (): void {
    Http::fake([
        'openbao.test/v1/transit/keys/tax-subject-kek/rotate' => Http::response([], 204),
        'openbao.test/v1/transit/keys/tax-subject-kek' => Http::response([
            'data' => ['latest_version' => 2],
        ]),
    ]);

    expect(makeService()->rotateKek())->toBe(2);
});

it('throws without leaking the token when the request fails', function (): void {
    Http::fake([
        'openbao.test/v1/transit/encrypt/tax-subject-kek' => Http::response(['errors' => ['permission denied']], 403),
    ]);

    $exception = null;

    try {
        makeService()->wrapDataKey(random_bytes(32));
    } catch (KeyManagementException $caught) {
        $exception = $caught;
    }

    expect($exception)->not->toBeNull();
    /** @var KeyManagementException $exception */
    expect($exception->getMessage())
        ->not->toContain('test-token')
        ->toContain('403');
});

it('throws on a malformed response instead of returning a bad wrap', function (): void {
    Http::fake([
        'openbao.test/v1/transit/encrypt/tax-subject-kek' => Http::response(['data' => ['unexpected' => true]]),
    ]);

    makeService()->wrapDataKey(random_bytes(32));
})->throws(KeyManagementException::class);

it('throws when transit returns a ciphertext with an unrecognised version prefix', function (): void {
    Http::fake([
        'openbao.test/v1/transit/encrypt/tax-subject-kek' => Http::response([
            'data' => ['ciphertext' => 'not-a-vault-ciphertext'],
        ]),
    ]);

    makeService()->wrapDataKey(random_bytes(32));
})->throws(KeyManagementException::class);

it('throws KeyManagementException, not a raw connection exception, when the host is unreachable', function (): void {
    Http::fake(function () {
        throw new ConnectException('simulated network failure', new Psr7Request('POST', 'http://openbao.test'));
    });

    makeService()->wrapDataKey(random_bytes(32));
})->throws(KeyManagementException::class);
