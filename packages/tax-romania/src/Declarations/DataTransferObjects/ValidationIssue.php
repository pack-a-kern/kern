<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\DataTransferObjects;

/**
 * One finding from `Kern\TaxRomania\Declarations\Services\DeclarationValidator`. `$code` is a stable,
 * machine-matchable identifier (e.g. `ASSESSMENT_NOT_FINAL`) — tests and future callers key off
 * this, never off `$message`'s exact wording.
 */
final readonly class ValidationIssue
{
    public function __construct(
        public string $code,
        public string $message,
    ) {
    }
}
