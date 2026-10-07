<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\DataTransferObjects;

use Kern\TaxRomania\TaxEngine\Exceptions\MissingRequiredParameterException;

/**
 * Every {@see ResolvedFiscalParameter} a rule declared it needs, keyed by parameter code and
 * already resolved for the assessment's `as_of` date — a rule reads this map, it never queries
 * `fiscal_parameter_versions` itself (`docs/AGENTS.md` §11.2 purity).
 */
final readonly class ResolvedParameterSet
{
    /**
     * @param  array<string, ResolvedFiscalParameter>  $parameters
     * @param  list<string>  $statedByNoLaw codes whose year was read and found to state no value
     */
    public function __construct(
        private array $parameters,
        /**
         * Parameters the law of that year was read for and found not to state.
         *
         * A third state, between a value and a gap. "Nobody has read 2023" and "2023's law was read and sets no
         * rent expense allowance" are different facts — `fiscal_parameter_reads` records which, and the screen
         * already draws a dash for one and nothing for the other — and the engine knew only "present" or
         * "missing", so a year the law deliberately left blank failed as though the dataset were incomplete.
         *
         * Each rule decides what it means for itself. No expense allowance means deduct nothing; no *rate* would
         * mean something else entirely, and no rule should quietly read it as zero.
         */
        private array $statedByNoLaw = [],
    ) {
    }

    /**
     * Whether that year's law was read for this parameter and states no value.
     *
     * False does not mean present — it means the law was not read, or was read and does state one. A caller asks
     * this only after `has()` has told it there is no value.
     */
    public function statedByNoLaw(string $parameterCode): bool
    {
        return in_array($parameterCode, $this->statedByNoLaw, true);
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
