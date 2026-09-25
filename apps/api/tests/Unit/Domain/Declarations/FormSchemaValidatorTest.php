<?php

declare(strict_types=1);

use Kern\TaxRomania\Declarations\Exceptions\InvalidFormSchemaException;
use Kern\TaxRomania\Declarations\Services\FormSchemaValidator;

/**
 * @return array<string, mixed>
 */
function synthethicWellFormedSchema(): array
{
    return [
        'sections' => [
            [
                'code' => 'SYNTHETIC_SECTION_A',
                'order' => 1,
                'label' => 'Synthetic section A',
                'fields' => [
                    ['code' => 'SYNTHETIC_FIELD_1', 'order' => 1, 'label' => 'Field one', 'data_type' => 'DECIMAL'],
                    ['code' => 'SYNTHETIC_FIELD_2', 'order' => 2, 'label' => 'Field two', 'data_type' => 'STRING'],
                ],
            ],
        ],
    ];
}

it('accepts a well-formed schema', function (): void {
    (new FormSchemaValidator())->assertWellFormed(synthethicWellFormedSchema());
})->throwsNoExceptions();

it('rejects a schema missing the top-level sections key', function (): void {
    expect(fn () => (new FormSchemaValidator())->assertWellFormed(['foo' => 'bar']))
        ->toThrow(InvalidFormSchemaException::class);
});

it('rejects a section missing a code', function (): void {
    $schema = ['sections' => [['order' => 1, 'label' => 'x', 'fields' => []]]];

    expect(fn () => (new FormSchemaValidator())->assertWellFormed($schema))
        ->toThrow(InvalidFormSchemaException::class);
});

it('rejects duplicate section codes', function (): void {
    $section = ['code' => 'DUP', 'order' => 1, 'label' => 'x', 'fields' => []];
    $schema = ['sections' => [$section, $section]];

    expect(fn () => (new FormSchemaValidator())->assertWellFormed($schema))
        ->toThrow(InvalidFormSchemaException::class, 'duplicate section code');
});

it('rejects a field with an unknown data_type', function (): void {
    $schema = [
        'sections' => [[
            'code' => 'S',
            'order' => 1,
            'label' => 'x',
            'fields' => [['code' => 'F', 'order' => 1, 'label' => 'x', 'data_type' => 'NOT_A_TYPE']],
        ]],
    ];

    expect(fn () => (new FormSchemaValidator())->assertWellFormed($schema))
        ->toThrow(InvalidFormSchemaException::class, 'unknown data_type');
});

it('rejects duplicate field codes within one section', function (): void {
    $field = ['code' => 'DUP_FIELD', 'order' => 1, 'label' => 'x', 'data_type' => 'STRING'];
    $schema = ['sections' => [['code' => 'S', 'order' => 1, 'label' => 'x', 'fields' => [$field, $field]]]];

    expect(fn () => (new FormSchemaValidator())->assertWellFormed($schema))
        ->toThrow(InvalidFormSchemaException::class, 'duplicate field code');
});
