<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\LegislationSectionPersisterTest;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Enums\SourceDocumentVersionStatus;
use Kern\Legislation\Exceptions\UnknownSourceDocumentVersionException;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Parsing\ParsedLegislationDocument;
use Kern\Legislation\Parsing\ParsedSection;
use Kern\Legislation\Services\LegislationSectionPersister;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/**
 * Builds a small, clearly SYNTHETIC parsed tree (invented placeholder legal text, not real law)
 * for testing persistence mechanics independent of the HTML parser (covered separately by
 * tests/Unit/Legislation/LegislatieJustRoHtmlParserTest.php).
 */
function syntheticParsedDocument(?DateTimeImmutable $publicationDate = null): ParsedLegislationDocument
{
    $litera = new ParsedSection(SectionType::LITERA, 'a)', 'Art. 1 alin. (1) lit. a)', 1, 'SYNTHETIC: litera text', []);
    $alineat = new ParsedSection(SectionType::ALINEAT, '(1)', 'Art. 1 alin. (1)', 1, 'SYNTHETIC: alineat text', [$litera]);
    $article = new ParsedSection(SectionType::ARTICOL, 'Articolul 1', 'Art. 1', 1, 'SYNTHETIC: article heading', [$alineat]);

    return new ParsedLegislationDocument($publicationDate, [$article]);
}

it('persists a parsed tree as ext_source_sections rows with correct parent/child links', function (): void {
    $version = SourceDocumentVersion::factory()->create();

    $result = app(LegislationSectionPersister::class)->persist($version->id, syntheticParsedDocument());

    expect($result->sectionCount)->toBe(3)
        ->and(SourceSection::query()->count())->toBe(3);

    $article = SourceSection::query()->where('citation_label', 'Art. 1')->firstOrFail();
    $alineat = SourceSection::query()->where('citation_label', 'Art. 1 alin. (1)')->firstOrFail();
    $litera = SourceSection::query()->where('citation_label', 'Art. 1 alin. (1) lit. a)')->firstOrFail();

    expect($article->parent_id)->toBeNull()
        ->and($alineat->parent_id)->toBe($article->id)
        ->and($litera->parent_id)->toBe($alineat->id)
        ->and($article->section_type)->toBe(SectionType::ARTICOL)
        ->and($litera->text)->toBe('SYNTHETIC: litera text')
        ->and($litera->text_hash)->toBe(hash('sha256', 'SYNTHETIC: litera text'));
});

it('marks the version REQUIRES_REVIEW when no effective date is given — never guessed', function (): void {
    $version = SourceDocumentVersion::factory()->create();

    app(LegislationSectionPersister::class)->persist($version->id, syntheticParsedDocument());

    $version->refresh();
    expect($version->status)->toBe(SourceDocumentVersionStatus::REQUIRES_REVIEW)
        ->and($version->effective_from)->toBeNull();
});

it('marks the version PARSED only when an effective date is explicitly supplied by the caller', function (): void {
    $version = SourceDocumentVersion::factory()->create();

    app(LegislationSectionPersister::class)->persist(
        $version->id,
        syntheticParsedDocument(),
        effectiveFrom: new DateTimeImmutable('2026-01-01'),
    );

    $version->refresh();
    expect($version->status)->toBe(SourceDocumentVersionStatus::PARSED)
        ->and($version->effective_from?->format('Y-m-d'))->toBe('2026-01-01');
});

it('records the parser_version used', function (): void {
    $version = SourceDocumentVersion::factory()->create();

    app(LegislationSectionPersister::class)->persist($version->id, syntheticParsedDocument(), parserVersion: '9.9.9-test');

    expect($version->refresh()->parser_version)->toBe('9.9.9-test');
});

it('replaces the whole section set on re-parse rather than duplicating', function (): void {
    $version = SourceDocumentVersion::factory()->create();
    $persister = app(LegislationSectionPersister::class);

    $persister->persist($version->id, syntheticParsedDocument());
    expect(SourceSection::query()->count())->toBe(3);

    $singleArticle = new ParsedLegislationDocument(null, [
        new ParsedSection(SectionType::ARTICOL, 'Articolul 2', 'Art. 2', 1, 'SYNTHETIC: replaced', []),
    ]);
    $result = $persister->persist($version->id, $singleArticle);

    expect($result->sectionCount)->toBe(1)
        ->and(SourceSection::query()->count())->toBe(1)
        ->and(SourceSection::query()->first()?->citation_label)->toBe('Art. 2');
});

it('backfills ext_source_documents.publication_date once, and never overwrites it on a later parse', function (): void {
    $version = SourceDocumentVersion::factory()->create();
    $persister = app(LegislationSectionPersister::class);

    $persister->persist($version->id, syntheticParsedDocument(new DateTimeImmutable('2020-05-01')));

    /** @var SourceDocument $document */
    $document = SourceDocument::query()->findOrFail($version->source_document_id);
    expect($document->publication_date?->format('Y-m-d'))->toBe('2020-05-01');

    $persister->persist($version->id, syntheticParsedDocument(new DateTimeImmutable('2099-01-01')));

    expect($document->refresh()->publication_date?->format('Y-m-d'))->toBe('2020-05-01');
});

it('throws for an unknown source_document_version id', function (): void {
    app(LegislationSectionPersister::class)->persist('00000000-0000-0000-0000-000000000000', syntheticParsedDocument());
})->throws(UnknownSourceDocumentVersionException::class);

it('stores a provision\'s notes beside it, with the dates they give, and never in its text', function (): void {
    $version = SourceDocumentVersion::factory()->create();
    $note = 'Potrivit alin. (2) al art. 10 din ORDONANȚA DE URGENȚĂ nr. 8 din 24 februarie 2026, publicată în MONITORUL OFICIAL nr. 147 din 25 februarie 2026, prevederile art. 6 pct. 6 se aplică începând cu anul fiscal 2026.';
    $article = new ParsedSection(SectionType::ARTICOL, 'Articolul 1', 'Art. 1', 1, 'SYNTHETIC: article text', [], notes: [$note]);

    app(LegislationSectionPersister::class)->persist($version->id, new ParsedLegislationDocument(null, [$article]));

    $section = SourceSection::query()->where('citation_label', 'Art. 1')->firstOrFail();

    expect($section->text)->toBe('SYNTHETIC: article text')
        ->and($section->notes[0]['text'] ?? null)->toBe($note)
        ->and($section->notes[0]['applies'][0]['from'] ?? null)->toBe('2026-01-01');
});

it('keeps each provision\'s id when the same version is read again, and drops only what is gone', function (): void {
    $version = SourceDocumentVersion::factory()->create();

    app(LegislationSectionPersister::class)->persist($version->id, syntheticParsedDocument());
    $before = SourceSection::query()->pluck('id', 'citation_label')->all();

    // Read again, the letter gone: the article and paragraph keep their ids, updated in place.
    $alineat = new ParsedSection(SectionType::ALINEAT, '(1)', 'Art. 1 alin. (1)', 1, 'SYNTHETIC: alineat text, reworded', []);
    $article = new ParsedSection(SectionType::ARTICOL, 'Articolul 1', 'Art. 1', 1, 'SYNTHETIC: article heading', [$alineat]);
    app(LegislationSectionPersister::class)->persist($version->id, new ParsedLegislationDocument(null, [$article]));

    $after = SourceSection::query()->pluck('id', 'citation_label')->all();

    expect($after)->toBe(['Art. 1' => $before['Art. 1'], 'Art. 1 alin. (1)' => $before['Art. 1 alin. (1)']])
        ->and(SourceSection::query()->whereKey($before['Art. 1 alin. (1)'])->sole()->text)->toBe('SYNTHETIC: alineat text, reworded');
});
