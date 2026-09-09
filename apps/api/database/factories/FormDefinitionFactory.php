<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Declarations\Models\FormDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FormDefinition>
 *
 * `code` is never `"D212"` or any other real ANAF form code — every test fixture in this codebase
 * uses a `SYNTHETIC_*` marker, the same convention `App\Domain\TaxEngine`'s own factories
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
