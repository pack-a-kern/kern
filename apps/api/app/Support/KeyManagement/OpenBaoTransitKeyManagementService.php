<?php

declare(strict_types=1);

namespace App\Support\KeyManagement;

use App\Support\KeyManagement\Contracts\KeyManagementService;
use App\Support\KeyManagement\Exceptions\KeyManagementException;
use App\Support\KeyManagement\ValueObjects\WrappedDataKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Wraps/unwraps per-subject DEKs using OpenBao's transit secrets engine (docker/openbao/init.sh
 * provisions the `chacha20-poly1305` transit key this talks to — docs/handoff/001-docker-topology.md
 * decision #5. This class never re-implements AEAD itself; it only calls transit's
 * encrypt/decrypt/rotate/key-info HTTP endpoints and never sees the KEK.
 *
 * Ciphertext returned by transit is self-describing (`vault:v<N>:<data>`), so the KEK version
 * is parsed from it rather than tracked separately — there is no way for this class to return a
 * `WrappedDataKey` whose `$kekVersion` disagrees with what would actually be used to unwrap it.
 *
 * Every failure path here throws `KeyManagementException` built from operation name + HTTP
 * status only — never from the response body (which could, in a misconfigured transit engine,
 * echo request data back) and never from the request (which carries the bearer token).
 */
final readonly class OpenBaoTransitKeyManagementService implements KeyManagementService
{
    private const string CIPHERTEXT_PATTERN = '/^vault:v(?<version>\d+):/';

    public function __construct(
        private HttpFactory $http,
        private string $address,
        private string $token,
        private string $transitMount,
        private string $keyName,
        private int $timeoutSeconds,
    ) {
    }

    public function wrapDataKey(string $plaintextDek): WrappedDataKey
    {
        $response = $this->post('encrypt', "{$this->transitMount}/encrypt/{$this->keyName}", [
            'plaintext' => base64_encode($plaintextDek),
        ]);

        $ciphertext = $response->json('data.ciphertext');

        if (! is_string($ciphertext) || $ciphertext === '') {
            throw KeyManagementException::malformedResponse('encrypt');
        }

        return new WrappedDataKey($ciphertext, self::parseVersion($ciphertext, 'encrypt'));
    }

    public function unwrapDataKey(WrappedDataKey $wrapped): string
    {
        $response = $this->post('decrypt', "{$this->transitMount}/decrypt/{$this->keyName}", [
            'ciphertext' => $wrapped->ciphertext,
        ]);

        $plaintextBase64 = $response->json('data.plaintext');

        if (! is_string($plaintextBase64) || $plaintextBase64 === '') {
            throw KeyManagementException::malformedResponse('decrypt');
        }

        $plaintext = base64_decode($plaintextBase64, true);

        if ($plaintext === false) {
            throw KeyManagementException::malformedResponse('decrypt');
        }

        return $plaintext;
    }

    public function currentKekVersion(): int
    {
        $response = $this->get('read-key', "{$this->transitMount}/keys/{$this->keyName}");

        $version = $response->json('data.latest_version');

        if (! is_int($version)) {
            throw KeyManagementException::malformedResponse('read-key');
        }

        return $version;
    }

    public function rotateKek(): int
    {
        // NOT `[]`: passing an empty PHP array here is a real bug, not a harmless no-op.
        // Laravel's HTTP client JSON-encodes `[]` as a JSON *array* (`[]`), and OpenBao's
        // rotate endpoint rejects that body with "failed to decode JSON input: json: cannot
        // unmarshal array into Go value of type map[string]interface {}" (HTTP 400) — confirmed
        // against a real running OpenBao, see docs/handoff/005-crypto-core.md "How to verify".
        // A non-empty associative array JSON-encodes as an object (`{...}`), which transit's
        // rotate endpoint accepts, ignoring the unrecognised field with a warning (also
        // confirmed live) — rotate takes no real parameters.
        $this->post('rotate', "{$this->transitMount}/keys/{$this->keyName}/rotate", ['_unused' => true]);

        return $this->currentKekVersion();
    }

    /**
     * @param array<string, bool|string> $payload
     */
    private function post(string $operation, string $path, array $payload): Response
    {
        try {
            $response = $this->client()->post($path, $payload);
        } catch (ConnectionException) {
            throw KeyManagementException::unreachable($operation);
        }

        return $this->assertSuccessful($operation, $response);
    }

    private function get(string $operation, string $path): Response
    {
        try {
            $response = $this->client()->get($path);
        } catch (ConnectionException) {
            throw KeyManagementException::unreachable($operation);
        }

        return $this->assertSuccessful($operation, $response);
    }

    private function assertSuccessful(string $operation, Response $response): Response
    {
        if ($response->failed()) {
            throw KeyManagementException::requestFailed($operation, $response->status());
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->address, '/') . '/v1/')
            ->withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeoutSeconds);
    }

    private static function parseVersion(string $ciphertext, string $operation): int
    {
        if (preg_match(self::CIPHERTEXT_PATTERN, $ciphertext, $matches) !== 1) {
            throw KeyManagementException::malformedCiphertext($operation);
        }

        return (int) $matches['version'];
    }
}
