<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\DataTransferObjects;

final readonly class ValidationResult
{
    /**
     * @param list<ValidationIssue> $issues
     */
    public function __construct(
        public bool $passed,
        public array $issues,
    ) {
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    public static function fromIssues(array $issues): self
    {
        return new self($issues === [], $issues);
    }
}
