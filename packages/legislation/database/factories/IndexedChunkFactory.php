<?php

declare(strict_types=1);

namespace Kern\Legislation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Legislation\Retrieval\Models\IndexedChunk;

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
        ];
    }
}
