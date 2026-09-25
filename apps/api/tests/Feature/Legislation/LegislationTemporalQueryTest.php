<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\LegislationTemporalQueryTest;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Exceptions\AmbiguousEffectiveVersionException;
use Kern\Legislation\Exceptions\NoEffectiveVersionFoundException;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Services\LegislationTemporalQuery;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/**
 * This is the milestone's central proof (`docs/handoff/018-legislation-ingestion.md` — getting
 * "what was in force on date X" right is what Milestones 4, 5 and 11 all depend on). All text
 * below is SYNTHETIC, invented placeholder content, not real Romanian law.
 */
it('returns the 2023 text for a 2023 date and NEVER the 2026 text — the never-mix-periods proof', function (): void {
    $document = SourceDocument::factory()->create();

    $version2023 = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2023-01-01',
        'effective_until' => '2025-12-31',
    ]);
    SourceSection::factory()->create([
        'document_version_id' => $version2023->id,
        'citation_label' => 'Art. 1',
        'text' => 'SYNTHETIC TEXT VALID IN 2023',
    ]);

    $version2026 = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2026-01-01',
        'effective_until' => null,
    ]);
    SourceSection::factory()->create([
        'document_version_id' => $version2026->id,
        'citation_label' => 'Art. 1',
        'text' => 'SYNTHETIC TEXT VALID IN 2026',
    ]);

    $query = app(LegislationTemporalQuery::class);

    $resolved2023 = $query->versionInForceOn($document->id, new DateTimeImmutable('2023-06-15'));
    expect($resolved2023->id)->toBe($version2023->id);

    $sections2023 = $query->sectionsInForceOn($document->id, new DateTimeImmutable('2023-06-15'));
    expect($sections2023)->toHaveCount(1)
        ->and($sections2023[0]->text)->toBe('SYNTHETIC TEXT VALID IN 2023')
        ->and($sections2023[0]->text)->not->toContain('2026');

    $resolved2026 = $query->versionInForceOn($document->id, new DateTimeImmutable('2026-06-01'));
    expect($resolved2026->id)->toBe($version2026->id);

    $sections2026 = $query->sectionsInForceOn($document->id, new DateTimeImmutable('2026-06-01'));
    expect($sections2026)->toHaveCount(1)
        ->and($sections2026[0]->text)->toBe('SYNTHETIC TEXT VALID IN 2026')
        ->and($sections2026[0]->text)->not->toContain('2023');
});

it('throws rather than guessing when no version covers the requested date', function (): void {
    $document = SourceDocument::factory()->create();
    SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2023-01-01',
        'effective_until' => '2025-12-31',
    ]);

    app(LegislationTemporalQuery::class)->versionInForceOn($document->id, new DateTimeImmutable('2020-01-01'));
})->throws(NoEffectiveVersionFoundException::class);

it('throws rather than guessing when a version has no effective_from set yet (REQUIRES_REVIEW)', function (): void {
    $document = SourceDocument::factory()->create();
    SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => null,
        'effective_until' => null,
    ]);

    app(LegislationTemporalQuery::class)->versionInForceOn($document->id, new DateTimeImmutable('2026-01-01'));
})->throws(NoEffectiveVersionFoundException::class);

it('throws on overlapping effective ranges — a data defect, never silently resolved', function (): void {
    $document = SourceDocument::factory()->create();
    SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2023-01-01',
        'effective_until' => '2026-12-31',
    ]);
    SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2025-01-01',
        'effective_until' => null,
    ]);

    app(LegislationTemporalQuery::class)->versionInForceOn($document->id, new DateTimeImmutable('2025-06-01'));
})->throws(AmbiguousEffectiveVersionException::class);

it('lets a section\'s own effective range override its version\'s, when narrower', function (): void {
    $document = SourceDocument::factory()->create();
    $version = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);

    SourceSection::factory()->create([
        'document_version_id' => $version->id,
        'citation_label' => 'Art. 1',
        'text' => 'Always in force within the version range.',
    ]);
    SourceSection::factory()->create([
        'document_version_id' => $version->id,
        'citation_label' => 'Art. 2',
        'text' => 'Only in force from 2026 onward.',
        'effective_from' => '2026-01-01',
        'effective_until' => null,
    ]);

    $query = app(LegislationTemporalQuery::class);

    $before = $query->sectionsInForceOn($document->id, new DateTimeImmutable('2024-01-01'));
    expect($before)->toHaveCount(1)
        ->and($before[0]->citationLabel)->toBe('Art. 1');

    $after = $query->sectionsInForceOn($document->id, new DateTimeImmutable('2026-06-01'));
    expect($after)->toHaveCount(2);
});

it('filters sections by type when requested', function (): void {
    $document = SourceDocument::factory()->create();
    $version = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);

    $article = SourceSection::factory()->create([
        'document_version_id' => $version->id,
        'section_type' => SectionType::ARTICOL,
        'citation_label' => 'Art. 1',
    ]);
    SourceSection::factory()->create([
        'document_version_id' => $version->id,
        'parent_id' => $article->id,
        'section_type' => SectionType::ALINEAT,
        'citation_label' => 'Art. 1 alin. (1)',
    ]);

    $onlyArticles = app(LegislationTemporalQuery::class)->sectionsInForceOn(
        $document->id,
        new DateTimeImmutable('2024-01-01'),
        SectionType::ARTICOL,
    );

    expect($onlyArticles)->toHaveCount(1)
        ->and($onlyArticles[0]->sectionType)->toBe(SectionType::ARTICOL);
});
