<?php

declare(strict_types=1);

namespace Kern\Legislation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Legislation\Models\SourceDocument;

/**
 * @extends Factory<SourceDocument>
 */
final class SourceDocumentFactory extends Factory
{
    protected $model = SourceDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'official_source_id' => OfficialSourceFactory::new(),
            'document_type' => 'FX_DAILY',
            'external_identifier' => null,
            'canonical_url' => 'https://example.test/feed.xml',
        ];
    }
}
