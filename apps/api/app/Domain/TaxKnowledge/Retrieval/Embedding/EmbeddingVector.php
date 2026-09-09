<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Retrieval\Embedding;

use InvalidArgumentException;

/**
 * One embedding result — a dense vector of finite floats, always non-empty. Deliberately has no
 * notion of "which model produced this" on the value object itself: that provenance is a property
 * of the *call* ({@see EmbeddingProvider::modelIdentifier()}), not of each individual vector,
 * matching how a single provider/model config applies to a whole `embed()` call (ADR 0007 —
 * provider selection is configuration, not a per-call choice).
 */
final readonly class EmbeddingVector
{
    /**
     * @param list<float> $values
     */
    public function __construct(public array $values)
    {
        if ($this->values === []) {
            throw new InvalidArgumentException('An embedding vector must not be empty.');
        }

        foreach ($this->values as $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('An embedding vector must contain only finite floats.');
            }
        }
    }

    public function dimension(): int
    {
        return count($this->values);
    }

    /**
     * The pgvector text input format (`[v1,v2,...]`) — what
     * {@see \App\Domain\TaxKnowledge\Retrieval\Indexing\EmbedIndexedChunks} binds directly as a
     * plain string parameter. Postgres resolves an untyped bound parameter against the target
     * `vector` column/cast at the point of use; no PHP-side pgvector client library is needed for
     * this one-directional write (verified empirically against a real `vector` column before this
     * class was written — see `docs/handoff/029-hybrid-retrieval.md`).
     */
    public function toPgvectorLiteral(): string
    {
        return '[' . implode(',', array_map(
            static fn (float $value): string => rtrim(rtrim(sprintf('%.17F', $value), '0'), '.'),
            $this->values,
        )) . ']';
    }
}
