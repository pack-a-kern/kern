<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Rental\Enums\PropertyType;
use App\Domain\Rental\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 *
 * `tax_subject_id` must still be supplied explicitly by the caller (an already-created
 * `TaxSubject`) — `Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst` makes attribute order
 * in that call irrelevant, the same shape `Database\Factories\FinancialAccountFactory` documents.
 * `ownership_percent_decimal` defaults to full (sole) ownership — the common case; co-ownership
 * tests override it explicitly.
 */
final class PropertyFactory extends Factory
{
    protected $model = Property::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => PropertyType::APARTMENT,
            'address_ciphertext' => 'Test Property ' . Str::uuid(),
            'ownership_percent_decimal' => '100.00',
        ];
    }
}
