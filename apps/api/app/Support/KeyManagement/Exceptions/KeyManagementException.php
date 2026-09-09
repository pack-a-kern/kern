<?php

declare(strict_types=1);

namespace App\Support\KeyManagement\Exceptions;

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
