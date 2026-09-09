<?php

declare(strict_types=1);

use App\Domain\TaxEngine\DataTransferObjects\CalculationStep;
use App\Domain\TaxEngine\DataTransferObjects\CalculationTrace;

it('serialises to the exact shape BLUEPRINT.md §11.4 describes', function (): void {
    $trace = new CalculationTrace(
        ruleCode: 'RO.TEST.CASS',
        ruleVersion: '1.2.0',
        inputs: ['eligible_income' => '36000.00'],
        steps: [new CalculationStep('threshold_select', ['threshold' => '24000.00'], '36000.00')],
        sourceRefs: ['section-abc'],
        result: '1440.00',
    );

    expect($trace->toArray())->toBe([
        'rule' => 'RO.TEST.CASS',
        'rule_version' => '1.2.0',
        'inputs' => ['eligible_income' => '36000.00'],
        'steps' => [
            ['operation' => 'threshold_select', 'detail' => ['threshold' => '24000.00'], 'result' => '36000.00'],
        ],
        'source_refs' => ['section-abc'],
        'result' => '1440.00',
    ]);
});

it('is JSON round-trippable, since this is exactly what persists to calculation_trace_json', function (): void {
    $trace = new CalculationTrace('RO.TEST.X', '1.0.0', [], [], [], '0.00');

    $json = json_encode($trace->toArray(), JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBe($trace->toArray());
});
