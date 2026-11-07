<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\KeyManagement\OpenBaoTransitKeyManagementServiceTest;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Kern\Platform\KeyManagement\Exceptions\KeyManagementException;
use Kern\Platform\KeyManagement\OpenBaoTransitKeyManagementService;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;

/**
 * Exercises the OpenBao transit adapter's request construction and response parsing against a
 * faked HTTP layer (`Http::fake()`), so these run in every CI invocation without a live OpenBao.
 * `tests/Feature/Platform/KeyManagement/OpenBaoLiveIntegrationTest.php` separately proves the same
 * class against a real dev-mode OpenBao, guarded behind an env var since CI does not start one.
 */
/** @param positive-int $hmacBatchSize */
function makeService(?string $tokenFile = null, ?string $caCertFile = null, int $hmacBatchSize = 250): OpenBaoTransitKeyManagementService
{
    return new OpenBaoTransitKeyManagementService(
        app(HttpFactory::class),
        'http://openbao.test',
        'test-token',
        $tokenFile,
        $caCertFile ?? true,
        'transit',
        'tax-subject-kek',
        'blind-index',
        5,
        $hmacBatchSize,
    );
}

/*
 * The server is verified, always.
 *
 * What crosses this connection is the token and every data key it unwraps. The bundled server's
 * certificate is signed by a CA it issues itself, so the client is pointed at that CA's file; with none
 * configured it uses the system's trust store — and in neither case is verification turned off.
 */
it('verifies the server against the CA it is configured with', function (): void {
    $verify = null;
    Http::fake(function ($request, array $options) use (&$verify) {
        $verify = $options['verify'] ?? null;

        return Http::response(['data' => ['ciphertext' => 'vault:v1:abc123']]);
    });

    makeService(caCertFile: '/run/kern/openbao/ca.crt')->wrapDataKey(random_bytes(32));

    expect($verify)->toBe('/run/kern/openbao/ca.crt');
});

it('verifies the server against the system trust store when no CA is configured', function (): void {
    $verify = null;
    Http::fake(function ($request, array $options) use (&$verify) {
        $verify = $options['verify'] ?? null;

        return Http::response(['data' => ['ciphertext' => 'vault:v1:abc123']]);
    });

    makeService()->wrapDataKey(random_bytes(32));

    expect($verify)->toBeTrue();
});

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

it('reads the token from the file on every call, so a replaced token is used at once', function (): void {
    // The bundled server publishes a new token before the old one expires; a queue worker that
    // read it once at start-up would keep presenting the old one until it was revoked.
    $file = tempnam(sys_get_temp_dir(), 'kern-openbao-token');
    file_put_contents($file, "first-token\n");

    Http::fake([
        'openbao.test/v1/transit/keys/tax-subject-kek' => Http::response(['data' => ['latest_version' => 1]]),
    ]);

    $service = makeService($file);
    $service->currentKekVersion();
    file_put_contents($file, 'second-token');
    $service->currentKekVersion();

    $sent = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('Authorization')[0] ?? '');

    expect($sent->all())->toBe(['Bearer first-token', 'Bearer second-token']);

    unlink($file);
});

it('refuses to fall back to the environment token when the file is configured but missing', function (): void {
    // Falling back would keep presenting a token the server has already replaced and will revoke.
    Http::fake();

    expect(fn () => makeService('/nonexistent/kern-openbao-token')->currentKekVersion())
        ->toThrow(KeyManagementException::class, 'token file is missing or empty');

    Http::assertNothingSent();
});

it('reports how long the token has left', function (): void {
    Http::fake([
        'openbao.test/v1/auth/token/lookup-self' => Http::response(['data' => ['ttl' => 2_160_000, 'expire_time' => '2026-11-20T14:09:23Z']]),
    ]);

    expect(makeService()->accessSecondsRemaining())->toBe(2_160_000);
});

it('reports a token that never expires as null, not as zero seconds left', function (): void {
    Http::fake([
        'openbao.test/v1/auth/token/lookup-self' => Http::response(['data' => ['ttl' => 0, 'expire_time' => null]]),
    ]);

    expect(makeService()->accessSecondsRemaining())->toBeNull();
});

it('computes blind indexes in one batch request and returns them as hex, in order', function (): void {
    $first = random_bytes(32);
    $second = random_bytes(32);

    Http::fake([
        'openbao.test/v1/transit/hmac/blind-index/sha2-256' => Http::response(['data' => ['batch_results' => [
            ['hmac' => 'vault:v1:' . base64_encode($first)],
            ['hmac' => 'vault:v1:' . base64_encode($second)],
        ]]]),
    ]);

    expect(makeService()->blindIndexes(['a', 'b']))->toBe([bin2hex($first), bin2hex($second)]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['batch_input'] === [
        ['input' => base64_encode('a')],
        ['input' => base64_encode('b')],
    ]);
});

// OpenBao refuses a body of too many JSON strings — 500 values at once was, 499 was not — and a
// statement import hashes every row at once. The adapter sends them in batches, answered in order.
it('sends many values in batches of its size, and answers in the order asked', function (): void {
    $answers = [];

    Http::fake(['openbao.test/v1/transit/hmac/blind-index/sha2-256' => static function ($request) use (&$answers) {
        return Http::response(['data' => ['batch_results' => array_map(static function (array $item) use (&$answers): array {
            $answers[base64_decode($item['input'], true)] = $bytes = hash('sha256', $item['input'], true);

            return ['hmac' => 'vault:v1:' . base64_encode($bytes)];
        }, $request['batch_input'])]]);
    }]);

    $values = ['a', 'b', 'c', 'd', 'e'];
    $indexes = makeService(hmacBatchSize: 2)->blindIndexes($values);

    Http::assertSentCount(3);
    expect($indexes)->toBe(array_map(static fn (string $value): string => bin2hex($answers[$value]), $values));
});

it('sends nothing for an empty batch', function (): void {
    Http::fake();

    expect(makeService()->blindIndexes([]))->toBe([]);

    Http::assertNothingSent();
});

it('refuses a batch with an item that failed, rather than skipping it', function (): void {
    // A missing index is a missing row: an import would store a fingerprint nothing can match.
    Http::fake([
        'openbao.test/v1/transit/hmac/blind-index/sha2-256' => Http::response(['data' => ['batch_results' => [
            ['hmac' => 'vault:v1:' . base64_encode(random_bytes(32))],
            ['error' => 'something went wrong'],
        ]]]),
    ]);

    expect(fn () => makeService()->blindIndexes(['a', 'b']))
        ->toThrow(KeyManagementException::class, 'malformed: operation="hmac"');
});
