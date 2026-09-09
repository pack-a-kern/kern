<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\DataTransferObjects;

use App\Domain\TaxEngine\Exceptions\MissingRequiredParameterException;

/**
 * Every {@see ResolvedFiscalParameter} a rule declared it needs, keyed by parameter code and
 * already resolved for the assessment's `as_of` date — a rule reads this map, it never queries
 * `fiscal_parameter_versions` itself (`docs/AGENTS.md` §11.2 purity).
 */
final readonly class ResolvedParameterSet
{
    /**
     * @param array<string, ResolvedFiscalParameter> $parameters
     */
    public function __construct(
        private array $parameters,
    ) {
    }

    public function get(string $parameterCode): ResolvedFiscalParameter
    {
        return $this->parameters[$parameterCode] ?? throw MissingRequiredParameterException::forCode($parameterCode);
    }

    public function has(string $parameterCode): bool
    {
        return array_key_exists($parameterCode, $this->parameters);
    }

    /**
     * @return list<ResolvedFiscalParameter>
     */
    public function all(): array
    {
        return array_values($this->parameters);
    }
}
