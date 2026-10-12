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

it('embeds pending texts and records provenance on the vector, using the deterministic test fake', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $section = SourceSection::factory()->create(['text' => 'SYNTHETIC text about contribuții sociale.']);
    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    $embeddedCount = app(EmbedIndexedChunks::class)();

    expect($embeddedCount)->toBe(1);

    // Provenance sits with the vector, not with the chunk: which model produced a set of numbers
    // is a fact about the text that was sent, and the same text reached through 91 consolidations
    // was sent once.
    $chunk = IndexedChunk::query()->where('section_id', $section->id)->sole();
    $vector = DB::table('ext_chunk_embeddings')->where('text_hash', $chunk->text_hash)->sole();

    expect($vector->embedding_model)->toBe('test-fake/hashed-bag-of-words-v1')
        ->and((int) $vector->embedding_dim)->toBe(DeterministicHashEmbeddingProvider::dimension())
        ->and($vector->created_at)->not->toBeNull();
});

/*
 * The ordering this class got wrong once. A provider does not know what produced a vector until it
 * has produced one — the service reports its own name in the response — so asking for the name
 * before the call gets a stand-in, and the stand-in was a different string. The result was one
 * model stored under two names and 1,044 texts cached twice; the migration
 * `2026_10_12_140000_one_vector_per_distinct_chunk_text.php` cleans that up, and this keeps it
 * from coming back. The fake's name is a constant, so only a provider that learns its own name on
 * the call can catch it.
 */
it('records the name the provider reports after embedding, not one it offered beforehand', function (): void {
    $provider = new class () implements EmbeddingProvider {
        private ?string $reported = null;

        public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::PASSAGE): array
        {
            $this->reported = 'reported-by-the-service@main';

            return (new DeterministicHashEmbeddingProvider())->embed($texts, $purpose);
        }

        public function modelIdentifier(): string
        {
            if ($this->reported === null) {
                throw new RuntimeException('asked which model produced a vector before producing one');
            }

            return $this->reported;
        }
    };

    app()->instance(EmbeddingProvider::class, $provider);

    $section = SourceSection::factory()->create(['text' => 'SYNTHETIC text, embedded once.']);
    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    expect(app(EmbedIndexedChunks::class)())->toBe(1);

    $chunk = IndexedChunk::query()->where('section_id', $section->id)->sole();

    expect(DB::table('ext_chunk_embeddings')->where('text_hash', $chunk->text_hash)->value('embedding_model'))
        ->toBe('reported-by-the-service@main');
});

it('never re-embeds a text that already has a vector', function (): void {
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

    expect(DB::table('ext_chunk_embeddings')->where('text_hash', $chunkA->text_hash)->exists())->toBeTrue()
        ->and(DB::table('ext_chunk_embeddings')->where('text_hash', $chunkB->text_hash)->exists())->toBeFalse();
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

it('asks for nothing when the next consolidation repeats a text already embedded', function (): void {
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

    // Nothing to do, rather than something cheap to do: the later chunk already reaches a vector
    // through its text's hash, so there is no row to fill and no copy to make.
    expect(app(EmbedIndexedChunks::class)())->toBe(0);

    $hashes = DB::table('ext_source_section_chunks')
        ->whereIn('section_id', [$earlier->id, $later->id])
        ->pluck('text_hash');

    expect($hashes)->toHaveCount(2)
        ->and($hashes->unique())->toHaveCount(1)
        ->and(DB::table('ext_chunk_embeddings')->where('text_hash', $hashes->first())->count())->toBe(1);
});
