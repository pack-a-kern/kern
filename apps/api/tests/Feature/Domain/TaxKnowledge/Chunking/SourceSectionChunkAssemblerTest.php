<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\Chunking\SourceSectionChunkAssemblerTest;

use App\Domain\TaxKnowledge\Chunking\SourceSectionChunkAssembler;
use App\Domain\TaxKnowledge\Enums\TrustTier;
use App\Domain\TaxKnowledge\Exceptions\UnknownSourceSectionException;
use App\Domain\TaxKnowledge\Models\OfficialSource;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('assembles the full BLUEPRINT.md §15.3 metadata chain from the database for a section', function (): void {
    $source = OfficialSource::factory()->create(['code' => 'LEGISLATIE', 'trust_tier' => TrustTier::A]);
    $document = SourceDocument::factory()->create([
        'official_source_id' => $source->id,
        'document_type' => 'LEGE',
        'issuer' => 'Parlamentul României',
        'publication_date' => '2020-01-15',
    ]);
    $version = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2026-01-01',
        'effective_until' => null,
    ]);
    $section = SourceSection::factory()->create([
        'document_version_id' => $version->id,
        'citation_label' => 'Art. 7',
        'text' => 'SYNTHETIC: text of article seven.',
    ]);

    $chunks = app(SourceSectionChunkAssembler::class)->chunksFor($section->id);

    expect($chunks)->toHaveCount(1);
    $chunk = $chunks[0];

    expect($chunk->sectionId)->toBe($section->id)
        ->and($chunk->documentVersionId)->toBe($version->id)
        ->and($chunk->sourceDocumentId)->toBe($document->id)
        ->and($chunk->sourceCode)->toBe('LEGISLATIE')
        ->and($chunk->trustTier)->toBe(TrustTier::A)
        ->and($chunk->issuer)->toBe('Parlamentul României')
        ->and($chunk->documentType)->toBe('LEGE')
        ->and($chunk->citationLabel)->toBe('Art. 7')
        ->and($chunk->effectiveFrom?->format('Y-m-d'))->toBe('2026-01-01')
        ->and($chunk->publicationDate?->format('Y-m-d'))->toBe('2020-01-15')
        ->and($chunk->text)->toBe('SYNTHETIC: text of article seven.');
});

it('uses the section\'s own effective dates over the version\'s when the section overrides them', function (): void {
    $version = SourceDocumentVersion::factory()->create([
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);
    $section = SourceSection::factory()->create([
        'document_version_id' => $version->id,
        'effective_from' => '2027-01-01',
        'effective_until' => '2027-12-31',
    ]);

    $chunks = app(SourceSectionChunkAssembler::class)->chunksFor($section->id);

    expect($chunks[0]->effectiveFrom?->format('Y-m-d'))->toBe('2027-01-01')
        ->and($chunks[0]->effectiveUntil?->format('Y-m-d'))->toBe('2027-12-31');
});

it('throws for an unknown section id', function (): void {
    app(SourceSectionChunkAssembler::class)->chunksFor('00000000-0000-0000-0000-000000000000');
})->throws(UnknownSourceSectionException::class);
