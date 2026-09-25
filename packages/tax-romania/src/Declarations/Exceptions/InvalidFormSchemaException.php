<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Exceptions;

use InvalidArgumentException;

final class InvalidFormSchemaException extends InvalidArgumentException
{
    public static function forReason(string $reason): self
    {
        return new self("Malformed form_versions.schema_json: {$reason}");
    }
}
