<?php

declare(strict_types=1);

namespace Kern\Platform\KeyManagement\Exceptions;

use RuntimeException;

/**
 * Every factory method here builds a message from operation names and HTTP status codes only.
 * Never pass a request body, response body, token, plaintext DEK, or ciphertext into this
 * exception — BLUEPRINT.md §4.10 forbids secrets in exception messages, and §23 forbids PII in
 * logs (uncaught exceptions are logged). Callers must not append extra context to these
 * messages; construct a new factory method instead if a new safe piece of context is needed.
 */
final class KeyManagementException extends RuntimeException
{
    public static function requestFailed(string $operation, int $httpStatus): self
    {
        return new self(sprintf(
            'KMS transit request failed: operation="%s" http_status=%d',
            $operation,
            $httpStatus,
        ));
    }

    /**
     * The key this server was asked to use does not exist.
     *
     * A distinct case because it is the only KMS failure that is not worth retrying. Every other one
     * says "ask again later"; this one says the master key is gone, and everything wrapped under it is
     * unreadable until it comes back — which, if it was never persisted, is never.
     *
     * OpenBao answers 404 both for a key that was never created and for one that was deleted, and it
     * cannot tell them apart. It happened on 2026-09-28: a kernel panic restarted a dev-mode server
     * whose storage was memory, and `requestFailed` logged it as `http_status=404` among ordinary
     * transport errors for fourteen hours. The message now says what it means.
     */
    public static function keyMissing(string $operation): self
    {
        return new self(sprintf(
            'KMS transit key does not exist — every data key wrapped under it is unreadable until it '
            . 'is restored: operation="%s" http_status=404',
            $operation,
        ));
    }

    /**
     * The token file this installation is configured to read is missing or empty.
     *
     * Its own case because nothing about the request is wrong: the key manager has not published a
     * token yet, or the volume it publishes to is not mounted here. Never carries the path's contents.
     */
    public static function tokenUnavailable(string $operation): self
    {
        return new self(sprintf('KMS token file is missing or empty: operation="%s"', $operation));
    }

    public static function unreachable(string $operation): self
    {
        return new self(sprintf('KMS transit endpoint unreachable: operation="%s"', $operation));
    }

    public static function malformedResponse(string $operation): self
    {
        return new self(sprintf('KMS transit response malformed: operation="%s"', $operation));
    }

    public static function malformedCiphertext(string $operation): self
    {
        return new self(sprintf(
            'KMS transit ciphertext has an unrecognised format: operation="%s"',
            $operation,
        ));
    }
}
