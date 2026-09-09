<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\Retrieval\IndexSourceSectionForRetrievalTest;

use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Retrieval\Indexing\IndexSourceSectionForRetrieval;
use App\Domain\TaxKnowledge\Retrieval\Models\IndexedChunk;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

it('persists one chunk with correct text and hash for a short section', function (): void {
    $section = SourceSection::factory()->create([
        'citation_label' => 'Art. 1',
        'text' => 'SYNTHETIC: short article text.',
    ]);

    $count = app(IndexSourceSectionForRetrieval::class)($section->id);

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

    $count = app(IndexSourceSectionForRetrieval::class)($section->id);

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

    app(IndexSourceSectionForRetrieval::class)($section->id);
    expect(IndexedChunk::query()->where('section_id', $section->id)->count())->toBe(1);

    $section->forceFill(['text' => 'SYNTHETIC: text after re-index, now longer than before.'])->save();
    app(IndexSourceSectionForRetrieval::class)($section->id);

    $chunk = IndexedChunk::query()->where('section_id', $section->id)->sole();
    expect($chunk->text)->toBe('SYNTHETIC: text after re-index, now longer than before.');
});

it('cascades: deleting a section deletes its indexed chunks', function (): void {
    $section = SourceSection::factory()->create([
        'citation_label' => 'Art. 4',
        'text' => 'SYNTHETIC: text to be deleted.',
    ]);

    app(IndexSourceSectionForRetrieval::class)($section->id);
    expect(IndexedChunk::query()->where('section_id', $section->id)->count())->toBe(1);

    $section->delete();

    expect(IndexedChunk::query()->where('section_id', $section->id)->count())->toBe(0);
});
