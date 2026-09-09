<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Exceptions;

use DateTimeImmutable;
use RuntimeException;

final class NoEffectiveVersionFoundException extends RuntimeException
{
    public static function forDate(string $sourceDocumentId, DateTimeImmutable $date): self
    {
        return new self(sprintf(
            'No source_document_versions row for source_document "%s" has an effective range covering %s.',
            $sourceDocumentId,
            $date->format('Y-m-d'),
        ));
    }
}
