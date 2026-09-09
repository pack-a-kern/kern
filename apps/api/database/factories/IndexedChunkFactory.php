<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxKnowledge\Retrieval\Models\IndexedChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndexedChunk>
 */
final class IndexedChunkFactory extends Factory
{
    protected $model = IndexedChunk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = 'SYNTHETIC FIXTURE CHUNK — ' . $this->faker->unique()->sentence();

        return [
            'section_id' => SourceSectionFactory::new(),
            'chunk_index' => 0,
            'chunk_count' => 1,
            'text' => $text,
            'text_hash' => hash('sha256', $text),
            'embedding_model' => null,
            'embedding_dim' => null,
            'embedded_at' => null,
        ];
    }
}
