<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support\Webauthn;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\ChallengeExpiredException;
use Throwable;
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
 * (`putRequestOptions`/`pullRequestOptions`) has no authenticated user yet, so `Kern\TaxRomania\
 * Identity\Support\Webauthn\PasskeyManager` keys it by the login email's blind index instead
 * (already a non-reversible HMAC, safe to use as a cache key, and the same value
 * `Kern\Platform\Identity\Models\User::scopeWhereEmail` already computes for lookup — no new secret
 * introduced). Either way, there is at most one in-flight ceremony per key at a time — starting a
 * second one before completing the first simply overwrites the pending challenge, which is correct
 * (the old one could never have completed anyway once superseded).
 *
 * ## Stored as JSON, not as the object
 *
 * The cache unserializes no PHP class at all (`config/cache.php` `serializable_classes => false`),
 * so that a leaked `APP_KEY` is not a gadget chain into the application. An options object put in
 * whole came back as `__PHP_Incomplete_Class`, failed the type check below, and every ceremony —
 * registration and login alike — ended as "challenge expired": no passkey had ever worked. So the
 * options go in as the WebAuthn JSON the library itself writes and reads, which keeps the cache's
 * protection whole instead of allow-listing classes through it.
 */
final readonly class ChallengeStore
{
    public function __construct(private CacheRepository $cache, private WebauthnSerializer $serializer)
    {
    }

    public function putCreationOptions(string $userId, PublicKeyCredentialCreationOptions $options): void
    {
        $this->put($this->creationKey($userId), $options);
    }

    public function pullCreationOptions(string $userId): PublicKeyCredentialCreationOptions
    {
        $options = $this->pull($this->creationKey($userId), PublicKeyCredentialCreationOptions::class);

        if (! $options instanceof PublicKeyCredentialCreationOptions) {
            throw ChallengeExpiredException::forCeremony('registration');
        }

        return $options;
    }

    public function putRequestOptions(string $key, PublicKeyCredentialRequestOptions $options): void
    {
        $this->put($this->requestKey($key), $options);
    }

    public function pullRequestOptions(string $key): PublicKeyCredentialRequestOptions
    {
        $options = $this->pull($this->requestKey($key), PublicKeyCredentialRequestOptions::class);

        if (! $options instanceof PublicKeyCredentialRequestOptions) {
            throw ChallengeExpiredException::forCeremony('assertion');
        }

        return $options;
    }

    private function put(string $key, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): void
    {
        $this->cache->put($key, $this->serializer->get()->serialize($options, 'json'), $this->ttl());
    }

    /**
     * The options stored under `$key`, evicted as they are read — or null when there are none, or
     * when what is there is not options of this kind. Either way the ceremony cannot complete, and
     * the caller says so.
     *
     * @param  class-string<PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions>  $class
     */
    private function pull(string $key, string $class): ?object
    {
        $json = $this->cache->pull($key);

        if (! is_string($json)) {
            return null;
        }

        try {
            return $this->serializer->get()->deserialize($json, $class, 'json');
        } catch (Throwable) {
            return null;
        }
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
