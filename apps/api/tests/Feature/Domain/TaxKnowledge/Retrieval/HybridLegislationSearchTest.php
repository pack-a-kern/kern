<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\Retrieval\HybridLegislationSearchTest;

use App\Domain\TaxKnowledge\Enums\TrustTier;
use App\Domain\TaxKnowledge\Models\OfficialSource;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider;
use App\Domain\TaxKnowledge\Retrieval\Indexing\EmbedIndexedChunks;
use App\Domain\TaxKnowledge\Retrieval\Indexing\IndexDocumentVersionForRetrieval;
use App\Domain\TaxKnowledge\Retrieval\Query\HybridLegislationSearch;
use App\Domain\TaxKnowledge\Retrieval\Query\HybridSearchQuery;
use App\Domain\TaxKnowledge\Retrieval\Query\RetrievalResult;
use App\Domain\TaxKnowledge\Retrieval\Query\TemporalConfidence;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

/**
 * All legal-sounding text below is SYNTHETIC, invented placeholder content
 * (`docs/AGENTS.md` §2) — never asserted as real Romanian law.
 */
function indexAndReturnSection(string $documentVersionId, string $citationLabel, string $text): SourceSection
{
    $section = SourceSection::factory()->create([
        'document_version_id' => $documentVersionId,
        'citation_label' => $citationLabel,
        'text' => $text,
    ]);

    app(IndexDocumentVersionForRetrieval::class)($section->document_version_id);

    return $section;
}

/**
 * @param list<RetrievalResult> $results
 */
function firstResultForSection(array $results, string $sectionId): RetrievalResult
{
    foreach ($results as $result) {
        if ($result->sectionId === $sectionId) {
            return $result;
        }
    }

    throw new RuntimeException("No retrieval result for section {$sectionId}.");
}

it('matches Romanian diacritics regardless of the query\'s or the source text\'s encoding', function (): void {
    $source = OfficialSource::factory()->create(['code' => 'LEGISLATIE', 'trust_tier' => TrustTier::A]);
    $document = SourceDocument::factory()->create(['official_source_id' => $source->id]);
    $version = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);

    // Comma-below Romanian letters (ș U+0219, ț U+021B) — the modern, correct Unicode encoding.
    $commaBelowText = 'Cota de impozitare pentru câștigurile din investiții este stabilită conform legii.';
    // The same text with only ș/ț swapped for the legacy cedilla forms (ş U+015F, ţ U+0163).
    $cedillaText = str_replace(['ș', 'ț', 'Ș', 'Ț'], ['ş', 'ţ', 'Ş', 'Ţ'], $commaBelowText);
    expect($cedillaText)->not->toBe($commaBelowText);

    indexAndReturnSection($version->id, 'Art. 5', $commaBelowText);
    indexAndReturnSection($version->id, 'Art. 6', $cedillaText);

    $search = app(HybridLegislationSearch::class);

    // The query itself carries no diacritics at all.
    $result = $search->search(new HybridSearchQuery(
        queryText: 'castigurile din investitii',
        asOf: new DateTimeImmutable('2024-01-01'),
    ));

    $labels = array_map(static fn ($r) => $r->citationLabel, $result->confirmed);
    expect($labels)->toContain('Art. 5')
        ->toContain('Art. 6');

    // And the reverse: an accented query must still find the cedilla-encoded source text.
    $resultAccented = $search->search(new HybridSearchQuery(
        queryText: 'câștigurile din investiții',
        asOf: new DateTimeImmutable('2024-01-01'),
    ));
    $accentedLabels = array_map(static fn ($r) => $r->citationLabel, $resultAccented->confirmed);
    expect($accentedLabels)->toContain('Art. 5')
        ->toContain('Art. 6');
});

it('fuzzy-matches a citation label typed without punctuation or diacritics', function (): void {
    $source = OfficialSource::factory()->create(['code' => 'LEGISLATIE', 'trust_tier' => TrustTier::A]);
    $document = SourceDocument::factory()->create(['official_source_id' => $source->id]);
    $version = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);

    indexAndReturnSection(
        $version->id,
        'Art. 170 alin. (2)',
        'SYNTHETIC filler prose sharing no words with the query below.',
    );

    $result = app(HybridLegislationSearch::class)->search(new HybridSearchQuery(
        queryText: 'art 170 alin 2',
        asOf: new DateTimeImmutable('2024-01-01'),
    ));

    expect($result->confirmed)->toHaveCount(1);
    $match = $result->confirmed[0];
    expect($match->citationLabel)->toBe('Art. 170 alin. (2)')
        ->and($match->citationSimilarity)->toBeGreaterThan(0.3)
        ->and($match->lexicalRank)->toBe(0.0);
});

it('never returns 2026 text for a 2023 query, and never 2023 text for a 2026 query', function (): void {
    $source = OfficialSource::factory()->create(['code' => 'LEGISLATIE', 'trust_tier' => TrustTier::A]);
    $document = SourceDocument::factory()->create(['official_source_id' => $source->id]);

    $version2023 = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2023-01-01',
        'effective_until' => '2025-12-31',
    ]);
    $version2026 = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2026-01-01',
        'effective_until' => null,
    ]);

    indexAndReturnSection($version2023->id, 'Art. 1', 'TEXT VALABIL IN 2023 despre impozitare pe venit.');
    indexAndReturnSection($version2026->id, 'Art. 1', 'TEXT VALABIL IN 2026 despre impozitare pe venit.');

    $search = app(HybridLegislationSearch::class);

    $for2023 = $search->search(new HybridSearchQuery(queryText: 'impozitare', asOf: new DateTimeImmutable('2023-06-15')));
    expect($for2023->confirmed)->toHaveCount(1)
        ->and($for2023->dateUnknown)->toBe([])
        ->and($for2023->confirmed[0]->text)->toContain('2023')
        ->and($for2023->confirmed[0]->text)->not->toContain('2026');

    $for2026 = $search->search(new HybridSearchQuery(queryText: 'impozitare', asOf: new DateTimeImmutable('2026-06-01')));
    expect($for2026->confirmed)->toHaveCount(1)
        ->and($for2026->dateUnknown)->toBe([])
        ->and($for2026->confirmed[0]->text)->toContain('2026')
        ->and($for2026->confirmed[0]->text)->not->toContain('2023');
});

it('surfaces a date-unknown match separately, never merged into confirmed and never silently dropped', function (): void {
    $source = OfficialSource::factory()->create(['code' => 'LEGISLATIE', 'trust_tier' => TrustTier::A]);

    $documentUnknown = SourceDocument::factory()->create(['official_source_id' => $source->id]);
    $versionUnknown = SourceDocumentVersion::factory()->create([
        'source_document_id' => $documentUnknown->id,
        'effective_from' => null,
        'effective_until' => null,
    ]);
    indexAndReturnSection($versionUnknown->id, 'Art. 9', 'TEXT WITH UNKNOWN DATES despre plafoane fiscale.');

    $documentKnown = SourceDocument::factory()->create(['official_source_id' => $source->id]);
    $versionKnown = SourceDocumentVersion::factory()->create([
        'source_document_id' => $documentKnown->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);
    indexAndReturnSection($versionKnown->id, 'Art. 10', 'TEXT WITH KNOWN DATES despre plafoane fiscale.');

    $result = app(HybridLegislationSearch::class)->search(new HybridSearchQuery(
        queryText: 'plafoane fiscale',
        asOf: new DateTimeImmutable('2024-01-01'),
    ));

    expect($result->confirmed)->toHaveCount(1)
        ->and($result->confirmed[0]->citationLabel)->toBe('Art. 10')
        ->and($result->confirmed[0]->temporalStatus)->toBe(TemporalConfidence::CONFIRMED);

    expect($result->dateUnknown)->toHaveCount(1)
        ->and($result->dateUnknown[0]->citationLabel)->toBe('Art. 9')
        ->and($result->dateUnknown[0]->temporalStatus)->toBe(TemporalConfidence::DATE_UNKNOWN);
});

it('degrades to lexical/fuzzy-only search when no embedding provider is configured', function (): void {
    $source = OfficialSource::factory()->create(['code' => 'LEGISLATIE', 'trust_tier' => TrustTier::A]);
    $document = SourceDocument::factory()->create(['official_source_id' => $source->id]);
    $version = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);
    indexAndReturnSection($version->id, 'Art. 1', 'TEXT despre venituri din dobânzi.');

    // The container's default binding is UnconfiguredEmbeddingProvider — not swapped here.
    $result = app(HybridLegislationSearch::class)->search(new HybridSearchQuery(
        queryText: 'venituri dobanzi',
        asOf: new DateTimeImmutable('2024-01-01'),
    ));

    expect($result->confirmed)->toHaveCount(1)
        ->and($result->confirmed[0]->semanticSimilarity)->toBeNull()
        ->and($result->confirmed[0]->lexicalRank)->toBeGreaterThan(0.0);
});

it('combines lexical and semantic signal into a single ranked score', function (): void {
    app()->instance(EmbeddingProvider::class, new DeterministicHashEmbeddingProvider());

    $source = OfficialSource::factory()->create(['code' => 'LEGISLATIE', 'trust_tier' => TrustTier::A]);
    $document = SourceDocument::factory()->create(['official_source_id' => $source->id]);
    $version = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);

    $relevant = indexAndReturnSection(
        $version->id,
        'Art. 1',
        'Regimul fiscal al veniturilor din chirii se aplică pentru anul 2026.',
    );
    $unrelated = indexAndReturnSection(
        $version->id,
        'Art. 2',
        'Cursul valutar oficial se publică zilnic de către banca centrală.',
    );

    app(EmbedIndexedChunks::class)($version->id);

    $result = app(HybridLegislationSearch::class)->search(new HybridSearchQuery(
        queryText: 'regimul fiscal chirii',
        asOf: new DateTimeImmutable('2024-01-01'),
    ));

    $matchedSectionIds = array_map(static fn ($r) => $r->sectionId, $result->confirmed);
    expect($matchedSectionIds)->toContain($relevant->id)
        ->not->toContain($unrelated->id);

    $match = firstResultForSection($result->confirmed, $relevant->id);
    expect($match->lexicalRank)->toBeGreaterThan(0.0)
        ->and($match->semanticSimilarity)->toBeGreaterThan(0.0);

    // The combined score is exactly the documented weighted formula — not a hidden extra term.
    $expected = ($match->semanticSimilarity * 0.5) + (min($match->lexicalRank, 1.0) * 0.3) + ($match->citationSimilarity * 0.2);
    expect($match->combinedScore)->toBeGreaterThanOrEqual($expected - 0.0001)
        ->and($match->combinedScore)->toBeLessThanOrEqual($expected + 0.0001);
});

it('carries a complete citation on every result', function (): void {
    $source = OfficialSource::factory()->create(['code' => 'LEGISLATIE', 'trust_tier' => TrustTier::A]);
    $document = SourceDocument::factory()->create([
        'official_source_id' => $source->id,
        'document_type' => 'LEGE',
        'issuer' => 'Parlamentul României',
    ]);
    $version = SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => '2020-01-01',
        'effective_until' => null,
    ]);
    $section = indexAndReturnSection($version->id, 'Art. 42', 'TEXT despre citarea completă a unei surse.');

    $result = app(HybridLegislationSearch::class)->search(new HybridSearchQuery(
        queryText: 'citarea completă',
        asOf: new DateTimeImmutable('2024-01-01'),
    ));

    expect($result->confirmed)->toHaveCount(1);
    $citation = $result->confirmed[0];

    expect($citation->chunkId)->not->toBe('')
        ->and($citation->sectionId)->toBe($section->id)
        ->and($citation->documentVersionId)->toBe($version->id)
        ->and($citation->sourceDocumentId)->toBe($document->id)
        ->and($citation->sourceCode)->toBe('LEGISLATIE')
        ->and($citation->trustTier)->toBe(TrustTier::A)
        ->and($citation->issuer)->toBe('Parlamentul României')
        ->and($citation->documentType)->toBe('LEGE')
        ->and($citation->citationLabel)->toBe('Art. 42')
        ->and($citation->effectiveFrom?->format('Y-m-d'))->toBe('2020-01-01')
        ->and($citation->text)->toBe('TEXT despre citarea completă a unei surse.');
});
