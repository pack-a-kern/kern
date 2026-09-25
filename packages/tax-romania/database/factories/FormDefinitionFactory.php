<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Kern\TaxRomania\Declarations\Models\FormDefinition;

/**
 * @extends Factory<FormDefinition>
 *
 * `code` is never `"D212"` or any other real ANAF form code — every test fixture in this codebase
 * uses a `SYNTHETIC_*` marker, the same convention `Kern\TaxRomania\TaxEngine`'s own factories
 * established (`docs/AGENTS.md` §2).
 */
final class FormDefinitionFactory extends Factory
{
    protected $model = FormDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'SYNTHETIC_TEST_FORM_' . Str::uuid(),
            'issuer' => 'SYNTHETIC_TEST_ISSUER',
        ];
    }
}
