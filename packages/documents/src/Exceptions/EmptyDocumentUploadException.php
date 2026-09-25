<?php

declare(strict_types=1);

namespace Kern\Documents\Exceptions;

use RuntimeException;

/**
 * An upload that named no files at all.
 *
 * Refused rather than allowed to create an empty document: a document with no parts has nothing to
 * scan, nothing to read and nothing to show, and would sit in the inbox forever in a state the
 * pipeline has no way to advance. The request validator catches this first; this exists so the
 * action cannot be talked into it by a caller that is not an HTTP request.
 */
final class EmptyDocumentUploadException extends RuntimeException
{
    public static function noFiles(): self
    {
        return new self('A document must be uploaded with at least one file.');
    }
}
