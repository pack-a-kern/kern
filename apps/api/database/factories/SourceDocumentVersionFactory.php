<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceDocumentVersion>
 */
final class SourceDocumentVersionFactory extends Factory
{
    protected $model = SourceDocumentVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bytes = $this->faker->unique()->sha256();

        return [
            'source_document_id' => SourceDocumentFactory::new(),
            'retrieved_at' => now('UTC'),
            'content_sha256' => hash('sha256', $bytes),
            'raw_object_path' => 'sources/test/' . hash('sha256', $bytes),
            'size_bytes' => strlen($bytes),
            'content_type' => 'application/xml',
        ];
    }
}
