<?php

declare(strict_types=1);

namespace Kern\Platform\KeyManagement;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\Exceptions\KeyManagementException;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;

/**
 * Wraps/unwraps per-subject DEKs using OpenBao's transit secrets engine (docker/openbao/entrypoint.sh
 * provisions the `chacha20-poly1305` transit key this talks to — docs/handoff/001-docker-topology.md
 * decision #5. This class never re-implements AEAD itself; it only calls transit's
 * encrypt/decrypt/rotate/key-info HTTP endpoints and never sees the KEK.
 *
 * Ciphertext returned by transit is self-describing (`vault:v<N>:<data>`), so the KEK version
 * is parsed from it rather than tracked separately — there is no way for this class to return a
 * `WrappedDataKey` whose `$kekVersion` disagrees with what would actually be used to unwrap it.
 *
 * ## The token is read on every call when it comes from a file
 *
 * The bundled server replaces its token before it expires and publishes the new one to a file. Reading
 * it once — into config, or into this object — would keep a long-running queue worker on the old one
 * until it was revoked. Reading a 64-byte file per call costs nothing next to the HTTP request it is for.
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
        private ?string $token,
        private ?string $tokenFile,
        // The CA file, or `true` for the system's — Kern\Platform\Http\CertificateAuthority.
        private string|true $verify,
        private string $transitMount,
        private string $keyName,
        private string $blindIndexKeyName,
        private int $timeoutSeconds,
        /**
         * Values per HMAC request: OpenBao refuses a body of too many JSON strings.
         *
         * @var positive-int
         */
        private int $hmacBatchSize,
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
        return $this->latestVersion('read-key', $this->keyName);
    }

    public function blindIndexes(array $normalizedValues): array
    {
        if ($normalizedValues === []) {
            return [];
        }

        // In batches: OpenBao refuses a request body of too many JSON strings, and a statement import
        // hashes every row's fingerprint at once. Each batch answers in its own order, so the answers
        // are put together in the order asked.
        $indexes = [];

        foreach (array_chunk($normalizedValues, $this->hmacBatchSize) as $batch) {
            $response = $this->post('hmac', "{$this->transitMount}/hmac/{$this->blindIndexKeyName}/sha2-256", [
                'batch_input' => array_map(
                    static fn (string $value): array => ['input' => base64_encode($value)],
                    $batch,
                ),
            ]);

            $results = $response->json('data.batch_results');

            if (! is_array($results) || count($results) !== count($batch)) {
                throw KeyManagementException::malformedResponse('hmac');
            }

            array_push($indexes, ...array_map(self::hexFromHmac(...), array_values($results)));
        }

        return $indexes;
    }

    public function currentBlindIndexKeyVersion(): int
    {
        return $this->latestVersion('read-blind-index-key', $this->blindIndexKeyName);
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

    public function accessSecondsRemaining(): ?int
    {
        // `lookup-self` is in OpenBao's default policy, which every token carries unless it was
        // created without it — so this needs nothing added to `kern-app`.
        $response = $this->get('lookup-self', 'auth/token/lookup-self');

        $ttl = $response->json('data.ttl');

        if (! is_int($ttl)) {
            throw KeyManagementException::malformedResponse('lookup-self');
        }

        // A token with no expiry reports 0 and no expire time; "0 seconds left" would read as expired.
        return $ttl === 0 && $response->json('data.expire_time') === null ? null : $ttl;
    }

    private function latestVersion(string $operation, string $keyName): int
    {
        $version = $this->get($operation, "{$this->transitMount}/keys/{$keyName}")->json('data.latest_version');

        if (! is_int($version)) {
            throw KeyManagementException::malformedResponse($operation);
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $operation, string $path, array $payload): Response
    {
        try {
            $response = $this->client($operation)->post($path, $payload);
        } catch (ConnectionException) {
            throw KeyManagementException::unreachable($operation);
        }

        return $this->assertSuccessful($operation, $response);
    }

    private function get(string $operation, string $path): Response
    {
        try {
            $response = $this->client($operation)->get($path);
        } catch (ConnectionException) {
            throw KeyManagementException::unreachable($operation);
        }

        return $this->assertSuccessful($operation, $response);
    }

    /**
     * A 404 is kept apart from every other failure, because it means something different.
     *
     * Transit answers 404 when the key named in the path is not there. Nothing about that is
     * transient: retrying cannot help, and a caller that treats it like a timeout hides the fact that
     * the master key is gone. See {@see KeyManagementException::keyMissing()}.
     */
    private function assertSuccessful(string $operation, Response $response): Response
    {
        if ($response->status() === 404) {
            throw KeyManagementException::keyMissing($operation);
        }

        if ($response->failed()) {
            throw KeyManagementException::requestFailed($operation, $response->status());
        }

        return $response;
    }

    private function client(string $operation): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->address, '/') . '/v1/')
            ->withToken($this->token($operation))
            // Verified always: against the CA the bundled server publishes when one is configured,
            // otherwise against the system's trust store. Read on every call, like the token, so a
            // renewed CA is trusted without a restart.
            ->withOptions(['verify' => $this->verify])
            ->acceptJson()
            ->timeout($this->timeoutSeconds);
    }

    /**
     * The file when one is configured, and nothing else then: falling back to an `.env` value would
     * quietly keep using a token the server has already replaced and will revoke.
     */
    private function token(string $operation): string
    {
        if ($this->tokenFile === null) {
            return $this->token ?? throw KeyManagementException::tokenUnavailable($operation);
        }

        $token = is_readable($this->tokenFile) ? trim((string) file_get_contents($this->tokenFile)) : '';

        return $token !== '' ? $token : throw KeyManagementException::tokenUnavailable($operation);
    }

    /**
     * `vault:v<N>:<base64>` as the plain hex every blind-index column holds.
     *
     * Without the version: an index is compared for equality, and a version inside it would make a
     * rotated key's indexes differ in two places instead of one. The version a corpus is on is the
     * KMS's to report ({@see currentBlindIndexKeyVersion()}). A batch item that failed carries an
     * `error` and no `hmac`, and is refused rather than skipped — a missing index is a missing row.
     */
    private static function hexFromHmac(mixed $result): string
    {
        $hmac = is_array($result) ? ($result['hmac'] ?? null) : null;

        if (! is_string($hmac) || preg_match(self::CIPHERTEXT_PATTERN, $hmac, $matches) !== 1) {
            throw KeyManagementException::malformedResponse('hmac');
        }

        $raw = base64_decode(substr($hmac, strlen($matches[0])), true);

        if ($raw === false || strlen($raw) !== 32) {
            throw KeyManagementException::malformedResponse('hmac');
        }

        return bin2hex($raw);
    }

    private static function parseVersion(string $ciphertext, string $operation): int
    {
        if (preg_match(self::CIPHERTEXT_PATTERN, $ciphertext, $matches) !== 1) {
            throw KeyManagementException::malformedCiphertext($operation);
        }

        return (int) $matches['version'];
    }
}
