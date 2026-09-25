<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\Retrieval\EmbedIndexedChunksTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Legislation\Retrieval\Embedding\EmbeddingPurpose;
use Kern\Legislation\Retrieval\Embedding\EmbeddingVector;
use Kern\Legislation\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;
use Kern\Legislation\Retrieval\Indexing\EmbedIndexedChunks;
use Kern\Legislation\Retrieval\Indexing\IndexDocumentVersionForRetrieval;
use Kern\Legislation\Retrieval\Models\IndexedChunk;
use RuntimeException;
use Tests\Fakes\DeterministicHashEmbeddingProvider;

/**
 * pgvector-backed retrieval: `ext_source_section_chunks.embedding` is a `vector` column that only
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
    // Forced off rather than assumed off. `TaxKnowledgeServiceProvider` binds the real HTTP
    // provider whenever `ai.embeddings.enabled` is true, and a developer machine with
    // `RETRIEVAL_EMBEDDING_ENABLED=true` in its `.env` would otherwise reach python-api and
    // succeed — so this test passed on CI and failed locally, which is the worst way for a test
    // about a *default* to behave. `forgetInstance` is needed because the binding is a singleton
    // already resolved by the time config changes here.
    config()->set('ai.embeddings.enabled', false);
    app()->forgetInstance(EmbeddingProvider::class);

    $section = SourceSection::factory()->create(['text' => 'SYNTHETIC text.']);
    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    app(EmbedIndexedChunks::class)();
})->throws(EmbeddingProviderNotConfiguredException::class);

it('embeds pending chunks and records provenance, using the deterministic test fake', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $section = SourceSection::factory()->create(['text' => 'SYNTHETIC text about contribuții sociale.']);
    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    $embeddedCount = app(EmbedIndexedChunks::class)();

    expect($embeddedCount)->toBe(1);

    $chunk = IndexedChunk::query()->where('section_id', $section->id)->sole();
    expect($chunk->embedding_model)->toBe('test-fake/hashed-bag-of-words-v1')
        ->and($chunk->embedding_dim)->toBe(DeterministicHashEmbeddingProvider::dimension())
        ->and($chunk->embedded_at)->not->toBeNull();
});

it('never re-embeds a chunk that already has an embedding', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $section = SourceSection::factory()->create(['text' => 'SYNTHETIC text, once.']);
    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    expect(app(EmbedIndexedChunks::class)())->toBe(1);
    expect(app(EmbedIndexedChunks::class)())->toBe(0);
});

it('scopes embedding to one document version when asked', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $versionA = SourceDocumentVersion::factory()->create();
    $versionB = SourceDocumentVersion::factory()->create();

    $sectionA = SourceSection::factory()->create(['document_version_id' => $versionA->id, 'text' => 'SYNTHETIC A.']);
    $sectionB = SourceSection::factory()->create(['document_version_id' => $versionB->id, 'text' => 'SYNTHETIC B.']);

    app(IndexDocumentVersionForRetrieval::class)($sectionA->document_version_id);
    app(IndexDocumentVersionForRetrieval::class)($sectionB->document_version_id);

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
    app(IndexDocumentVersionForRetrieval::class)($sectionOne->document_version_id);
    app(IndexDocumentVersionForRetrieval::class)($sectionTwo->document_version_id);

    app(EmbedIndexedChunks::class)();
})->throws(RuntimeException::class);

it('copies the vector of an identical text rather than embedding it again', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $earlier = SourceSection::factory()->create(['text' => 'SYNTHETIC: the same provision, unchanged.']);
    app(IndexDocumentVersionForRetrieval::class)($earlier->document_version_id);
    app(EmbedIndexedChunks::class)();

    // The next consolidation repeats it word for word.
    $later = SourceSection::factory()->create(['text' => 'SYNTHETIC: the same provision, unchanged.']);
    app(IndexDocumentVersionForRetrieval::class)($later->document_version_id);

    // A provider that would fail if asked: nothing should reach it.
    app()->instance(EmbeddingProvider::class, new class () implements EmbeddingProvider {
        public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::PASSAGE): array
        {
            throw new RuntimeException('asked to embed a text that was already embedded');
        }

        public function modelIdentifier(): string
        {
            return (new DeterministicHashEmbeddingProvider())->modelIdentifier();
        }
    });

    expect(app(EmbedIndexedChunks::class)())->toBe(1);

    $vectors = DB::table('ext_source_section_chunks')
        ->whereIn('section_id', [$earlier->id, $later->id])
        ->pluck('embedding')
        ->unique();

    expect($vectors)->toHaveCount(1);
});
