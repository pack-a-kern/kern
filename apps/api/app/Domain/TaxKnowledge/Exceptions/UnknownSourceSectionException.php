<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Exceptions;

use RuntimeException;

final class UnknownSourceSectionException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('No ext_source_sections row with id "%s".', $id));
    }
}
