<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support\Webauthn;

use App\Domain\Identity\Support\Webauthn\Exceptions\ChallengeExpiredException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Holds the server-side half of an in-flight WebAuthn ceremony (the full options object,
 * including the random challenge) between "begin" and "complete", one-time-use, per user.
 *
 * The challenge is never trusted from client input — `pull*()` reads and immediately evicts the
 * cache entry (`CacheRepository::pull()`), so replaying a "complete" call twice against the same
 * ceremony always fails the second time with `ChallengeExpiredException`, independent of the
 * cache backend's own TTL expiry.
 *
 * Keyed by an opaque identifier chosen by the caller, never by a client-supplied ceremony token.
 * Registration (`putCreationOptions`/`pullCreationOptions`) is always keyed by the authenticated
 * user's own id — that flow requires an existing session. Assertion/login
 * (`putRequestOptions`/`pullRequestOptions`) has no authenticated user yet, so `App\Domain\
 * Identity\Support\Webauthn\PasskeyManager` keys it by the login email's blind index instead
 * (already a non-reversible HMAC, safe to use as a cache key, and the same value
 * `App\Domain\Identity\Models\User::scopeWhereEmail` already computes for lookup — no new secret
 * introduced). Either way, there is at most one in-flight ceremony per key at a time — starting a
 * second one before completing the first simply overwrites the pending challenge, which is correct
 * (the old one could never have completed anyway once superseded).
 */
final readonly class ChallengeStore
{
    public function __construct(private CacheRepository $cache)
    {
    }

    public function putCreationOptions(string $userId, PublicKeyCredentialCreationOptions $options): void
    {
        $this->cache->put($this->creationKey($userId), $options, $this->ttl());
    }

    public function pullCreationOptions(string $userId): PublicKeyCredentialCreationOptions
    {
        $options = $this->cache->pull($this->creationKey($userId));

        if (! $options instanceof PublicKeyCredentialCreationOptions) {
            throw ChallengeExpiredException::forCeremony('registration');
        }

        return $options;
    }

    public function putRequestOptions(string $key, PublicKeyCredentialRequestOptions $options): void
    {
        $this->cache->put($this->requestKey($key), $options, $this->ttl());
    }

    public function pullRequestOptions(string $key): PublicKeyCredentialRequestOptions
    {
        $options = $this->cache->pull($this->requestKey($key));

        if (! $options instanceof PublicKeyCredentialRequestOptions) {
            throw ChallengeExpiredException::forCeremony('assertion');
        }

        return $options;
    }

    private function ttl(): int
    {
        $seconds = config('webauthn.challenge_ttl_seconds');

        return is_int($seconds) ? $seconds : 120;
    }

    private function creationKey(string $userId): string
    {
        return "webauthn:creation:{$userId}";
    }

    private function requestKey(string $key): string
    {
        return "webauthn:request:{$key}";
    }
}
