<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider;
use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingPurpose;
use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingVector;

/**
 * The test-only stand-in for `docs/handoff/029-hybrid-retrieval.md`'s embedding seam — never
 * bound in application code (`App\Domain\TaxKnowledge\TaxKnowledgeServiceProvider` binds
 * `App\Domain\TaxKnowledge\Retrieval\Embedding\UnconfiguredEmbeddingProvider` by default), never
 * makes a network call, and is fully deterministic: the same text always produces the same
 * vector, and two texts sharing words produce vectors with a genuinely higher cosine similarity
 * than two texts sharing none — a real (if crude) hashed bag-of-words embedding, not a random
 * one, so tests that assert "the hybrid ranking combines lexical and semantic signal" are testing
 * real arithmetic behaviour of `HybridLegislationSearch`'s SQL, not a canned fixture number.
 *
 * Dimension fixed at 64 to match `source_section_chunks.embedding`'s column type
 * (`2026_09_08_301101_create_source_section_chunks_table.php`).
 */
final class DeterministicHashEmbeddingProvider implements EmbeddingProvider
{
    public const int DIMENSION = 64;

    public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::PASSAGE): array
    {
        return array_map($this->embedOne(...), $texts);
    }

    public function modelIdentifier(): string
    {
        return 'test-fake/hashed-bag-of-words-v1';
    }

    private function embedOne(string $text): EmbeddingVector
    {
        $values = array_fill(0, self::DIMENSION, 0.0);

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $index = abs(crc32($word)) % self::DIMENSION;
            $values[$index] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $values)));

        if ($norm > 0.0) {
            $values = array_map(static fn (float $value): float => $value / $norm, $values);
        } else {
            // An empty/punctuation-only text has no words to hash — give it a fixed, non-zero
            // unit vector rather than an all-zero one, which pgvector's cosine distance operator
            // treats as undefined (division by zero magnitude).
            $values[0] = 1.0;
        }

        return new EmbeddingVector($values);
    }
}
