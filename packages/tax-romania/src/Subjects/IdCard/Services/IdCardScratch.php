<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\IdCard\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use JsonException;

/**
 * The only place an identity card, and then the reading of it, comes to rest between the request
 * that uploads it and the one that reads the result — and it comes to rest **encrypted, keyed by an
 * unguessable token, for seconds, once** (ADR 0028).
 *
 * Why here and not the queue: a queued job carrying the image would leave the card in Redis in the
 * clear until a worker took it. Instead the image is sealed into the cache under a token, the job
 * carries only the token, and the worker pulls-and-deletes it. The result (which holds a CNP) is
 * sealed the same way for the browser to fetch exactly once.
 *
 * Sealed with the application encrypter (APP_KEY), not a subject DEK: there is no subject yet, and
 * the value lives for seconds, not for keeps. Single-use is enforced by deleting on read; the
 * result is additionally bound to the user who uploaded, so a guessed token reveals nothing.
 */
final readonly class IdCardScratch
{
    public function __construct(
        private CacheRepository $cache,
        private Encrypter $encrypter,
        private ConfigRepository $config,
    ) {
    }

    public function putImage(string $token, string $bytes, string $mimeType): void
    {
        $this->cache->put(
            $this->imageKey($token),
            ['mime' => $mimeType, 'data' => $this->encrypter->encryptString($bytes)],
            $this->ttl(),
        );
    }

    /**
     * The uploaded card, decrypted, removed in the same step so it can never be read twice. Null if
     * it expired or was already taken.
     *
     * @return array{mime: string, bytes: string}|null
     */
    public function pullImage(string $token): ?array
    {
        $value = $this->cache->pull($this->imageKey($token));

        if (! is_array($value) || ! is_string($value['mime'] ?? null) || ! is_string($value['data'] ?? null)) {
            return null;
        }

        return ['mime' => $value['mime'], 'bytes' => $this->encrypter->decryptString($value['data'])];
    }

    /**
     * Seal the result for the browser, bound to the user who uploaded the card.
     *
     * @param  array<string, mixed>  $result
     */
    public function putResult(string $token, string $ownerUserId, array $result): void
    {
        try {
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return;
        }

        $this->cache->put(
            $this->resultKey($token),
            ['owner' => $ownerUserId, 'data' => $this->encrypter->encryptString($json)],
            $this->ttl(),
        );
    }

    /**
     * The sealed result, decrypted and removed — but only for the user it was sealed for. A token
     * presented by anyone else returns null and is left in place, so a guess cannot consume the real
     * owner's one read.
     *
     * @return array<string, mixed>|null
     */
    public function pullResult(string $token, string $forUserId): ?array
    {
        $value = $this->cache->get($this->resultKey($token));

        if (! is_array($value) || ($value['owner'] ?? null) !== $forUserId || ! is_string($value['data'] ?? null)) {
            return null;
        }

        $this->cache->forget($this->resultKey($token));

        try {
            $decoded = json_decode($this->encrypter->decryptString($value['data']), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function ttl(): int
    {
        return $this->config->integer('ai.idcard.read.scratch_ttl_seconds');
    }

    private function imageKey(string $token): string
    {
        return "idcard:img:{$token}";
    }

    private function resultKey(string $token): string
    {
        return "idcard:res:{$token}";
    }
}
