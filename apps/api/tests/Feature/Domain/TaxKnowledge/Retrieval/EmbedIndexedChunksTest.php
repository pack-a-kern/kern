<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\Retrieval\EmbedIndexedChunksTest;

use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider;
use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingPurpose;
use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingVector;
use App\Domain\TaxKnowledge\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;
use App\Domain\TaxKnowledge\Retrieval\Indexing\EmbedIndexedChunks;
use App\Domain\TaxKnowledge\Retrieval\Indexing\IndexSourceSectionForRetrieval;
use App\Domain\TaxKnowledge\Retrieval\Models\IndexedChunk;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fakes\DeterministicHashEmbeddingProvider;

/**
 * pgvector-backed retrieval: `source_section_chunks.embedding` is a `vector` column that only
 * exists on Postgres, so these must skip elsewhere rather than fail. Matches the convention the
 * Audit suite already uses for its Postgres-only trigger and locking tests.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires Postgres with pgvector — see docs/handoff/029-hybrid-retrieval.md.');
    }
});


beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('throws when no embedding provider is configured — the default binding', function (): void {
    $section = SourceSection::factory()->create(['text' => 'SYNTHETIC text.']);
    app(IndexSourceSectionForRetrieval::class)($section->id);

    app(EmbedIndexedChunks::class)();
})->throws(EmbeddingProviderNotConfiguredException::class);

it('embeds pending chunks and records provenance, using the deterministic test fake', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $section = SourceSection::factory()->create(['text' => 'SYNTHETIC text about contribuții sociale.']);
    app(IndexSourceSectionForRetrieval::class)($section->id);

    $embeddedCount = app(EmbedIndexedChunks::class)();

    expect($embeddedCount)->toBe(1);

    $chunk = IndexedChunk::query()->where('section_id', $section->id)->sole();
    expect($chunk->embedding_model)->toBe('test-fake/hashed-bag-of-words-v1')
        ->and($chunk->embedding_dim)->toBe(DeterministicHashEmbeddingProvider::DIMENSION)
        ->and($chunk->embedded_at)->not->toBeNull();
});

it('never re-embeds a chunk that already has an embedding', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $section = SourceSection::factory()->create(['text' => 'SYNTHETIC text, once.']);
    app(IndexSourceSectionForRetrieval::class)($section->id);

    expect(app(EmbedIndexedChunks::class)())->toBe(1);
    expect(app(EmbedIndexedChunks::class)())->toBe(0);
});

it('scopes embedding to one document version when asked', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $versionA = SourceDocumentVersion::factory()->create();
    $versionB = SourceDocumentVersion::factory()->create();

    $sectionA = SourceSection::factory()->create(['document_version_id' => $versionA->id, 'text' => 'SYNTHETIC A.']);
    $sectionB = SourceSection::factory()->create(['document_version_id' => $versionB->id, 'text' => 'SYNTHETIC B.']);

    app(IndexSourceSectionForRetrieval::class)($sectionA->id);
    app(IndexSourceSectionForRetrieval::class)($sectionB->id);

    $embedded = app(EmbedIndexedChunks::class)($versionA->id);

    expect($embedded)->toBe(1);

    $chunkA = IndexedChunk::query()->where('section_id', $sectionA->id)->sole();
    $chunkB = IndexedChunk::query()->where('section_id', $sectionB->id)->sole();

    expect($chunkA->embedded_at)->not->toBeNull()
        ->and($chunkB->embedded_at)->toBeNull();
});

it('throws when a provider violates its own contract by returning the wrong number of vectors', function (): void {
    $brokenProvider = new class () implements EmbeddingProvider {
        public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::PASSAGE): array
        {
            return [new EmbeddingVector([1.0, 0.0])];
        }

        public function modelIdentifier(): string
        {
            return 'broken/mismatched-count';
        }
    };

    app()->instance(EmbeddingProvider::class, $brokenProvider);

    $sectionOne = SourceSection::factory()->create(['text' => 'SYNTHETIC one.']);
    $sectionTwo = SourceSection::factory()->create(['text' => 'SYNTHETIC two.']);
    app(IndexSourceSectionForRetrieval::class)($sectionOne->id);
    app(IndexSourceSectionForRetrieval::class)($sectionTwo->id);

    app(EmbedIndexedChunks::class)();
})->throws(RuntimeException::class);
