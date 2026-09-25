<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Legislation\Retrieval\Embedding\EmbeddingPurpose;
use Kern\Legislation\Retrieval\Embedding\EmbeddingVector;

/**
 * The test-only stand-in for `docs/handoff/029-hybrid-retrieval.md`'s embedding seam — never
 * bound in application code (`Kern\Legislation\TaxKnowledgeServiceProvider` binds
 * `Kern\Legislation\Retrieval\Embedding\UnconfiguredEmbeddingProvider` by default), never
 * makes a network call, and is fully deterministic: the same text always produces the same
 * vector, and two texts sharing words produce vectors with a genuinely higher cosine similarity
 * than two texts sharing none — a real (if crude) hashed bag-of-words embedding, not a random
 * one, so tests that assert "the hybrid ranking combines lexical and semantic signal" are testing
 * real arithmetic behaviour of `HybridLegislationSearch`'s SQL, not a canned fixture number.
 *
 * The dimension is read from `config('ai.embeddings.dimension')` rather than fixed here.
 * `ext_source_section_chunks.embedding` is a `vector(N)` column and pgvector rejects any other width
 * outright, so a literal in this file is a literal that has to be changed in lockstep with a
 * migration — which is exactly what did not happen when
 * `2026_09_09_600000_widen_source_section_chunk_embeddings.php` widened the column from the
 * original 64 to 384 and left every embedding test failing with "expected 384 dimensions, not 64".
 */
final class DeterministicHashEmbeddingProvider implements EmbeddingProvider
{
    /** The width the column actually has — the same value `EmbedIndexedChunks` asserts against. */
    public static function dimension(): int
    {
        $configured = config('ai.embeddings.dimension');

        return is_int($configured) && $configured > 0 ? $configured : 384;
    }

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
        $dimension = self::dimension();
        $values = array_fill(0, $dimension, 0.0);

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $index = abs(crc32($word)) % $dimension;
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

        return new EmbeddingVector(array_values($values));
    }
}
