<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingVectorTest;

use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingVector;
use InvalidArgumentException;

it('rejects an empty vector', function (): void {
    new EmbeddingVector([]);
})->throws(InvalidArgumentException::class);

it('rejects a non-finite value', function (): void {
    new EmbeddingVector([1.0, NAN, 0.5]);
})->throws(InvalidArgumentException::class);

it('rejects an infinite value', function (): void {
    new EmbeddingVector([1.0, INF]);
})->throws(InvalidArgumentException::class);

it('reports its dimension', function (): void {
    $vector = new EmbeddingVector([0.1, 0.2, 0.3]);

    expect($vector->dimension())->toBe(3);
});

it('formats a pgvector literal with no trailing zeroes', function (): void {
    $vector = new EmbeddingVector([1.0, -0.5, 0.0, 0.25]);

    expect($vector->toPgvectorLiteral())->toBe('[1,-0.5,0,0.25]');
});
