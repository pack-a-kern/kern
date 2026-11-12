<?php

declare(strict_types=1);

namespace Kern\Legislation\Tests\Integration\StoredProvisionsTest;

use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;
use Kern\Legislation\Parsing\ParsedSection;
use Kern\Legislation\Provisions\StoredProvision;
use Kern\Legislation\Provisions\StoredProvisions;
use Kern\Legislation\Services\LegislationSectionPersister;

/**
 * A law's provisions as another package reads them: ORDIN 1.324/2026 of the Ministry of Finance, as
 * legislatie.just.ro serves it (public, the Official Gazette's text), stored as a reading stores it.
 *
 * @return array{0: string, 1: list<ParsedSection>} the law's id, and its provisions as they were read
 */
function storedOrder(): array
{
    $parsed = (new LegislatieJustRoHtmlParser())->parse((string) file_get_contents(__DIR__ . '/../Fixtures/Legislatie/ordin-1324-2026-prospect-tezaur.html'));
    $version = SourceDocumentVersion::factory()->create();
    app(LegislationSectionPersister::class)->persist($version->id, $parsed);

    return [$version->source_document_id, $parsed->topLevelSections];
}

/** @param list<ParsedSection|StoredProvision> $provisions */
function shape(array $provisions): array
{
    return array_map(static fn (ParsedSection|StoredProvision $p): array => [$p->sectionType, $p->citationLabel, $p->text, $p->tables, shape($p->children)], $provisions);
}

it('gives a law’s provisions as stored, each under its own, as they were read', function (): void {
    [$law, $read] = storedOrder();

    expect(shape(app(StoredProvisions::class)->latest($law)))->toBe(shape($read));
});

it('gives the latest version read, not one before it', function (): void {
    [$law] = storedOrder();
    $later = SourceDocumentVersion::factory()->create(['source_document_id' => $law, 'retrieved_at' => now()->addDay()]);
    app(LegislationSectionPersister::class)->persist($later->id, (new LegislatieJustRoHtmlParser())->parse(
        '<html><body><div class="content_forma_act"><span class="S_ART"><span class="S_ART_TTL">Articolul 1</span><span class="S_ART_BDY">Exemplul citit mai târziu.</span></span></div></body></html>',
    ));

    expect(shape(app(StoredProvisions::class)->latest($law)))->toHaveCount(1)
        ->and(app(StoredProvisions::class)->latest($law)[0]->text)->toBe('Exemplul citit mai târziu.');
});
