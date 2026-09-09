<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxEngine\Enums\AssessmentStatus;
use App\Domain\TaxEngine\Models\TaxAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxAssessment>
 */
final class TaxAssessmentFactory extends Factory
{
    protected $model = TaxAssessment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_subject_id' => TaxSubjectFactory::new(),
            'fiscal_year' => 2026,
            'as_of' => '2026-05-20',
            'ruleset_id' => 'SYNTHETIC-TEST-RULESET@0.0.1',
            'input_snapshot_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'status' => AssessmentStatus::DRAFT,
            'totals_json' => [],
            'calculation_trace_object_path' => null,
        ];
    }

    public function final(): static
    {
        return $this->state(['status' => AssessmentStatus::FINAL]);
    }
}
