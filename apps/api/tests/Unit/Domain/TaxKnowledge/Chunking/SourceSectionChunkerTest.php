<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\TaxKnowledge\Chunking\SourceSectionChunkerTest;

use DateTimeImmutable;
use Kern\Legislation\Chunking\ChunkableSection;
use Kern\Legislation\Chunking\SourceSectionChunker;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Enums\TrustTier;

function section(string $text): ChunkableSection
{
    return new ChunkableSection(
        sectionId: 'section-1',
        documentVersionId: 'version-1',
        sourceDocumentId: 'document-1',
        sourceCode: 'LEGISLATIE',
        trustTier: TrustTier::A,
        issuer: 'Parlamentul României',
        documentType: 'LEGE',
        sectionType: SectionType::ARTICOL,
        citationLabel: 'Art. 5',
        effectiveFrom: new DateTimeImmutable('2026-01-01'),
        effectiveUntil: null,
        publicationDate: new DateTimeImmutable('2025-12-01'),
        pageStart: null,
        pageEnd: null,
        text: $text,
    );
}

it('returns a single chunk for text within the limit, carrying all metadata', function (): void {
    $chunker = new SourceSectionChunker(maxChunkChars: 1000);

    $chunks = $chunker->chunk(section('Text scurt.'));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->text)->toBe('Text scurt.')
        ->and($chunks[0]->chunkIndex)->toBe(0)
        ->and($chunks[0]->chunkCount)->toBe(1)
        ->and($chunks[0]->sectionId)->toBe('section-1')
        ->and($chunks[0]->citationLabel)->toBe('Art. 5')
        ->and($chunks[0]->trustTier)->toBe(TrustTier::A)
        ->and($chunks[0]->effectiveFrom?->format('Y-m-d'))->toBe('2026-01-01');
});

it('splits long text on sentence boundaries, packing greedily toward the limit', function (): void {
    $chunker = new SourceSectionChunker(maxChunkChars: 20);

    $text = 'Prima propozitie scurta. A doua propozitie scurta. A treia.';
    $chunks = $chunker->chunk(section($text));

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $index => $chunk) {
        expect($chunk->chunkIndex)->toBe($index)
            ->and($chunk->chunkCount)->toBe(count($chunks));
    }

    expect(implode(' ', array_map(fn ($c) => $c->text, $chunks)))->toContain('Prima propozitie')
        ->and(implode(' ', array_map(fn ($c) => $c->text, $chunks)))->toContain('A treia.');
});

it('hard-splits a single sentence longer than the limit', function (): void {
    $chunker = new SourceSectionChunker(maxChunkChars: 10);

    $chunks = $chunker->chunk(section('abcdefghijklmnopqrstuvwxyz'));

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->text))->toBeLessThanOrEqual(10);
    }
});

it('returns a single empty chunk for empty text', function (): void {
    $chunks = (new SourceSectionChunker())->chunk(section(''));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->text)->toBe('');
});
