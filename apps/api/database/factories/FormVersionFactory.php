<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Declarations\Enums\FormVersionStatus;
use App\Domain\Declarations\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVersion>
 *
 * Defaults to `ACTIVE` and applicable to fiscal year 2026 — the common case a declaration test
 * needs to build against without also registering a `RegisterFormVersion` call. `schema_json` is
 * `null` by default (no real D212 schema exists to seed, `docs/AGENTS.md` §2); tests exercising
 * `App\Domain\Declarations\Services\FormSchemaValidator` supply their own synthetic schema.
 */
final class FormVersionFactory extends Factory
{
    protected $model = FormVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_definition_id' => FormDefinitionFactory::new(),
            'version_code' => '2026.1-SYNTHETIC',
            'applicable_from' => '2026-01-01',
            'applicable_until' => null,
            'tax_year_from' => 2026,
            'tax_year_to' => 2026,
            'schema_object_path' => null,
            'instructions_document_version_id' => null,
            'schema_json' => null,
            'status' => FormVersionStatus::ACTIVE,
        ];
    }

    public function retired(): static
    {
        return $this->state(['status' => FormVersionStatus::RETIRED]);
    }

    public function draft(): static
    {
        return $this->state(['status' => FormVersionStatus::DRAFT]);
    }
}
