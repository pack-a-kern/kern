<?php

declare(strict_types=1);

namespace App\Support\ObjectStore\Exceptions;

use RuntimeException;

/**
 * The object key itself is safe to include in the message (it is a structural identifier — two
 * UUIDs/ULIDs and a fixed kind name — never PII, per `App\Support\ObjectStore\ObjectKey`).
 */
final class ObjectNotFoundException extends RuntimeException
{
    public static function contentMissing(string $objectKey): self
    {
        return new self(sprintf('Object "%s" was not found in the object store.', $objectKey));
    }

    public static function manifestMissing(string $objectKey): self
    {
        return new self(sprintf('Integrity manifest for object "%s" was not found.', $objectKey));
    }
}
