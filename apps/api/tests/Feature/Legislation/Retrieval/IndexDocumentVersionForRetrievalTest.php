<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\Retrieval\IndexDocumentVersionForRetrievalTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Retrieval\Indexing\IndexDocumentVersionForRetrieval;
use Kern\Legislation\Retrieval\Models\IndexedChunk;

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

it('persists one chunk with correct text and hash for a short section', function (): void {
    $section = SourceSection::factory()->create([
        'citation_label' => 'Art. 1',
        'text' => 'SYNTHETIC: short article text.',
    ]);

    $count = app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    expect($count)->toBe(1);

    $chunk = IndexedChunk::query()->where('section_id', $section->id)->sole();
    expect($chunk->chunk_index)->toBe(0)
        ->and($chunk->chunk_count)->toBe(1)
        ->and($chunk->text)->toBe('SYNTHETIC: short article text.')
        ->and($chunk->text_hash)->toBe(hash('sha256', 'SYNTHETIC: short article text.'))
        ->and($chunk->embedding_model)->toBeNull()
        ->and($chunk->embedded_at)->toBeNull();
});

it('splits a long section into multiple ordered chunks', function (): void {
    $sentence = 'Aceasta este o propoziție sintetică folosită doar pentru a testa împărțirea în bucăți. ';
    $longText = trim(str_repeat($sentence, 40));

    $section = SourceSection::factory()->create([
        'citation_label' => 'Art. 2',
        'text' => $longText,
    ]);

    $count = app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    expect($count)->toBeGreaterThan(1);

    $chunks = IndexedChunk::query()->where('section_id', $section->id)->orderBy('chunk_index')->get();
    expect($chunks)->toHaveCount($count);

    foreach ($chunks as $index => $chunk) {
        expect($chunk->chunk_index)->toBe($index)
            ->and($chunk->chunk_count)->toBe($count);
    }
});

it('replaces the whole chunk set on re-index rather than duplicating', function (): void {
    $section = SourceSection::factory()->create([
        'citation_label' => 'Art. 3',
        'text' => 'SYNTHETIC: text before re-index.',
    ]);

    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);
    expect(IndexedChunk::query()->where('section_id', $section->id)->count())->toBe(1);

    $section->forceFill(['text' => 'SYNTHETIC: text after re-index, now longer than before.'])->save();
    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    $chunk = IndexedChunk::query()->where('section_id', $section->id)->sole();
    expect($chunk->text)->toBe('SYNTHETIC: text after re-index, now longer than before.');
});

it('cascades: deleting a section deletes its indexed chunks', function (): void {
    $section = SourceSection::factory()->create([
        'citation_label' => 'Art. 4',
        'text' => 'SYNTHETIC: text to be deleted.',
    ]);

    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);
    expect(IndexedChunk::query()->where('section_id', $section->id)->count())->toBe(1);

    $section->delete();

    expect(IndexedChunk::query()->where('section_id', $section->id)->count())->toBe(0);
});

it('indexes a table row by row, so a figure only a table holds is found', function (): void {
    $section = SourceSection::factory()->create([
        'citation_label' => 'Art. 5 alin. (1)',
        'text' => "Impozitul se calculează potrivit tabelului:\n[[table:1]]",
        'tables' => [[['Tranșe de venit brut', 'Impozit'], ['până la 66.750, inclusiv', '1%']]],
    ]);

    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    expect(IndexedChunk::query()->where('section_id', $section->id)->sole()->text)
        ->toBe("Impozitul se calculează potrivit tabelului:\nTranșe de venit brut | Impozit\npână la 66.750, inclusiv | 1%");
});
