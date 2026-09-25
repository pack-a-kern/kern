<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\FiscalParameters\Models\FiscalParameterVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Legislation\Database\Factories\SourceSectionFactory;
use Kern\Legislation\Enums\RuleApprovalStatus;

/**
 * @extends Factory<FiscalParameterVersion>
 */
final class FiscalParameterVersionFactory extends Factory
{
    protected $model = FiscalParameterVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_parameter_id' => FiscalParameterFactory::new(),
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'value_json' => ['amount_minor' => 100000, 'currency' => 'RON'],
            'source_section_id' => SourceSectionFactory::new(),
            'status' => RuleApprovalStatus::CANDIDATE,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }
}
