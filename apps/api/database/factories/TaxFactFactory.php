<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxEngine\Enums\TaxFactStatus;
use App\Domain\TaxEngine\Models\TaxFact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaxFact>
 */
final class TaxFactFactory extends Factory
{
    protected $model = TaxFact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_subject_id' => TaxSubjectFactory::new(),
            'fiscal_year' => 2026,
            'category_code' => 'SYNTHETIC_TEST_CATEGORY',
            'subtype_code' => null,
            'recognition_date' => '2026-03-15',
            'cash_date' => null,
            'earned_at' => null,
            'received_at' => null,
            'paid_at' => null,
            'distributed_at' => null,
            'contract_date' => null,
            'transaction_date' => null,
            'gross_amount_minor' => 100000,
            'deductible_amount_minor' => null,
            'withheld_tax_minor' => null,
            'currency_code' => 'RON',
            'ron_amount_minor' => 100000,
            'deductible_amount_ron_minor' => null,
            'withheld_tax_ron_minor' => null,
            'fx_conversion_json' => null,
            'source_type' => 'SYNTHETIC_TEST_SOURCE',
            'source_id' => (string) Str::uuid7(),
            'status' => TaxFactStatus::PROPOSED,
            'metadata_ciphertext' => null,
            'rule_input_version' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['status' => TaxFactStatus::CONFIRMED]);
    }
}
