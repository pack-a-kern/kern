<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\DataTransferObjects;

/**
 * One step of a {@see CalculationTrace} (`BLUEPRINT.md` §11.4's example: `{"operation":
 * "threshold_select", "result": "..."}`). `detail`/`result` must be JSON-serialisable scalars —
 * enforced structurally by the `array<string, scalar|null>`/`string` shapes below, not merely by
 * convention — and must never carry PII (`BLUEPRINT.md` §11.4 — "Trace nu include PII inutil"): a
 * step records intermediate *arithmetic*, never a fact's raw description or a subject's identity.
 */
final readonly class CalculationStep
{
    /**
     * @param array<string, string> $detail
     */
    public function __construct(
        public string $operation,
        public array $detail,
        public string $result,
    ) {
    }

    /**
     * @return array{operation: string, detail: array<string, string>, result: string}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'detail' => $this->detail,
            'result' => $this->result,
        ];
    }
}
