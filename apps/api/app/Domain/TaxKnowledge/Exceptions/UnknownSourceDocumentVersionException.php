<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Exceptions;

use RuntimeException;

final class UnknownSourceDocumentVersionException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('No source_document_versions row with id "%s".', $id));
    }
}
