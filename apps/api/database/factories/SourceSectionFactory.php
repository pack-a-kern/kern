<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\Models\SourceSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceSection>
 */
final class SourceSectionFactory extends Factory
{
    protected $model = SourceSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = 'SYNTHETIC FIXTURE TEXT — ' . $this->faker->unique()->sentence();
        $ordinal = $this->faker->unique()->numberBetween(1, 9000);

        return [
            'document_version_id' => SourceDocumentVersionFactory::new(),
            'parent_id' => null,
            'section_type' => SectionType::ARTICOL,
            'citation_label' => 'Art. ' . $ordinal,
            'ordinal' => $ordinal,
            'text' => $text,
            'text_hash' => hash('sha256', $text),
            'page_start' => null,
            'page_end' => null,
            'effective_from' => null,
            'effective_until' => null,
        ];
    }
}
