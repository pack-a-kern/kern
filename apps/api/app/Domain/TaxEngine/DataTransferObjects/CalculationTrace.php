<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\DataTransferObjects;

/**
 * `BLUEPRINT.md` §11.4 — every assessment line's full "what/why/when/source" derivation, reproducible
 * from data already produced at assessment time (`BLUEPRINT.md` §38-F). Deliberately flat and
 * JSON-serialisable (`toArray()`), because this is exactly what
 * `tax_assessment_lines.calculation_trace_json` persists verbatim.
 *
 * `inputs` must be safe to display to the subject who asked "why is my CASS X" — amounts and
 * dates, never raw fact descriptions or third-party identities (`BLUEPRINT.md` §11.4/§16 — broadcast
 * events carry status/progress only; the same discipline applies here to a persisted trace).
 */
final readonly class CalculationTrace
{
    /**
     * @param array<string, string> $inputs
     * @param list<CalculationStep> $steps
     * @param list<string> $sourceRefs
     */
    public function __construct(
        public string $ruleCode,
        public string $ruleVersion,
        public array $inputs,
        public array $steps,
        public array $sourceRefs,
        public string $result,
    ) {
    }

    /**
     * @return array{
     *     rule: string,
     *     rule_version: string,
     *     inputs: array<string, string>,
     *     steps: list<array{operation: string, detail: array<string, string>, result: string}>,
     *     source_refs: list<string>,
     *     result: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->ruleCode,
            'rule_version' => $this->ruleVersion,
            'inputs' => $this->inputs,
            'steps' => array_map(static fn (CalculationStep $step): array => $step->toArray(), $this->steps),
            'source_refs' => $this->sourceRefs,
            'result' => $this->result,
        ];
    }
}
