<?php

declare(strict_types=1);

namespace Kern\Documents\Exceptions;

use RuntimeException;

/**
 * This subject already holds a document with identical bytes.
 *
 * Identity is the SHA-256 of the content, never the filename: the same receipt photographed twice
 * produces two different files, and one file renamed twice is still one file. Only the hash answers
 * "have I already uploaded this?" truthfully.
 *
 * Carries the existing document's id so the caller can point at it — being told "you already have
 * this" is only useful alongside *where*.
 *
 * Deliberately not a `FileSecurityException`: nothing is wrong with the file. Refusing it is a
 * convenience for the person, not a defence, which is also why it maps to a 409 and not a 422.
 */
final class DuplicateDocumentException extends RuntimeException
{
    private function __construct(public readonly string $existingDocumentId)
    {
        parent::__construct("A document with identical content already exists as \"{$existingDocumentId}\".");
    }

    public static function of(string $existingDocumentId): self
    {
        return new self($existingDocumentId);
    }
}
