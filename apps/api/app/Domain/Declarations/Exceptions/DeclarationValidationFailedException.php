<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Exceptions;

use RuntimeException;

final class DeclarationValidationFailedException extends RuntimeException
{
    /**
     * @param list<\App\Domain\Declarations\DataTransferObjects\ValidationIssue> $issues
     */
    public function __construct(
        public readonly string $declarationId,
        public readonly array $issues,
    ) {
        $codes = implode(', ', array_map(static fn ($issue): string => $issue->code, $issues));

        parent::__construct("Declaration {$declarationId} failed validation: {$codes}");
    }
}
