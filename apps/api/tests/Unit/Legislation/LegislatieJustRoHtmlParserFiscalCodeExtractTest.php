<?php

declare(strict_types=1);

namespace Tests\Unit\Legislation\LegislatieJustRoHtmlParserFiscalCodeExtractTest;

use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;
use Kern\Legislation\Parsing\ParsedSection;
use RuntimeException;

/**
 * Regression test against `tests/Fixtures/TaxKnowledge/Legislatie/codul-fiscal-extract-2026-09-08.html`
 * — a REAL, verbatim extract of the Codul fiscal (Legea 227/2015), not synthetic text (see that
 * file's own leading HTML comment for exact provenance and what is trimmed vs. verbatim). This is
 * the regression fixture `docs/handoff/039-fiscal-code-parser-fix.md` requires: the three measured
 * defects it documents (letters collapsing onto the article, orphan letters with no article at
 * all, letters silently dropped) were only ever visible against the *real* Codul fiscal — the
 * Constitution fixture {@see LegislatieJustRoHtmlParserTest} never exercised them.
 *
 * Every number asserted below was produced by running this parser against the real 7.6 MB document
 * and is reconciled exactly against the real `S_ART`/`S_ALN`/`S_LIT`/`S_PCT`/`S_ANX` marker counts
 * in `docs/reference/fiscal-code-parse-defects.md` — nothing here is tuned to make a count match;
 * every excluded/pruned section is independently explained there with the real HTML evidence.
 */
function fiscalCodeExtractHtml(): string
{
    $path = fixture('Legislation/Legislatie/codul-fiscal-extract-2026-09-08.html');
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Could not read fixture: {$path}");
    }

    return $contents;
}

/**
 * @param list<ParsedSection> $sections
 * @return list<ParsedSection>
 */
function flattenSections(array $sections): array
{
    $flat = [];

    foreach ($sections as $section) {
        $flat[] = $section;
        array_push($flat, ...flattenSections($section->children));
    }

    return $flat;
}

/**
 * @param list<ParsedSection> $sections
 */
function findByLabel(array $sections, string $label): ?ParsedSection
{
    foreach (flattenSections($sections) as $section) {
        if ($section->citationLabel === $label) {
            return $section;
        }
    }

    return null;
}

/**
 * @param list<ParsedSection> $sections
 */
function findByLabelOrFail(array $sections, string $label): ParsedSection
{
    $section = findByLabel($sections, $label);

    if ($section === null) {
        throw new RuntimeException("Expected citation label not found in parsed tree: {$label}");
    }

    return $section;
}

it('parses the real Fiscal Code extract into exactly the expected section counts', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(fiscalCodeExtractHtml());

    $counts = [];
    foreach (flattenSections($result->topLevelSections) as $section) {
        $counts[$section->sectionType->value] = ($counts[$section->sectionType->value] ?? 0) + 1;
    }

    // ksort: $counts's key insertion order follows pre-order document traversal (e.g. Art. 3 has
    // no ALINEAT at all, so 'LITERA' is inserted before 'ALINEAT' first appears under Art. 40^3) —
    // an accident of which section type each article happens to reach first, not something this
    // test should pin down. Comparing counts, not key order.
    ksort($counts);

    expect($counts)->toBe([
        'ALINEAT' => 20,
        'ANEXA' => 1,
        'ARTICOL' => 8,
        'LITERA' => 29,
        'PUNCT' => 2,
    ]);
});

it('never produces a duplicate citation label, even from the extract\'s genuinely ambiguous sources', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(fiscalCodeExtractHtml());

    $labels = array_map(fn (ParsedSection $s) => $s->citationLabel, flattenSections($result->topLevelSections));

    expect($labels)->toBe(array_unique($labels));
});

it('reports the extract\'s four genuinely ambiguous source citations, pruned rather than persisted twice', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(fiscalCodeExtractHtml());

    expect($result->ambiguousCitationLabels)->toEqualCanonicalizing([
        'Art. 168 alin. (9^2)',
        'Art. 342 alin. (1^3)',
        'Art. 16 alin. (5^1) lit. a)',
        'Art. 16 alin. (5^1) lit. b)',
    ]);
});

it('attaches Art. 3\'s fiscal principles directly to the article with no ALINEAT (Defect 3 fix)', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(fiscalCodeExtractHtml());

    $article = findByLabelOrFail($result->topLevelSections, 'Art. 3');
    $literaA = findByLabelOrFail($result->topLevelSections, 'Art. 3 lit. a)');

    expect($article->sectionType)->toBe(SectionType::ARTICOL)
        ->and($literaA->sectionType)->toBe(SectionType::LITERA)
        ->and($literaA->text)->toContain('neutralitatea măsurilor fiscale');
});

it('reattaches Art. 40^3\'s orphaned lit. a)-d) to alin. (4), not to the article (Defect 1 fix)', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(fiscalCodeExtractHtml());

    $alineat4 = findByLabelOrFail($result->topLevelSections, 'Art. 40^3 alin. (4)');
    $literaA = findByLabelOrFail($result->topLevelSections, 'Art. 40^3 alin. (4) lit. a)');
    $orphanUnderArticle = findByLabel($result->topLevelSections, 'Art. 40^3 lit. a)');

    expect($alineat4->children)->toHaveCount(4)
        ->and($literaA->text)->toContain('transferă active de la sediul central din România')
        ->and($orphanUnderArticle)->toBeNull();
});

it('keeps a PUNCT nested inside a LITERA nested inside an ALINEAT (Art. 316)', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(fiscalCodeExtractHtml());

    $literaA = findByLabelOrFail($result->topLevelSections, 'Art. 316 alin. (1) lit. a)');
    $punct1 = findByLabelOrFail($result->topLevelSections, 'Art. 316 alin. (1) lit. a) pct. 1.');

    expect($literaA->children)->toHaveCount(2)
        ->and($punct1->sectionType)->toBe(SectionType::PUNCT);
});

it('models a per-Titlu annex with no "nr. N" using the real enclosing Titlu/Capitol text', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(fiscalCodeExtractHtml());

    $annex = findByLabelOrFail($result->topLevelSections, 'Titlul VII Capitolul XV Anexa');

    expect($annex->sectionType)->toBe(SectionType::ANEXA);
});

it('excludes an S_NTA note that quotes another act\'s own numbered provisions, at any nesting depth', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(fiscalCodeExtractHtml());

    // The two S_NTA fixtures each reproduce, for context, a DIFFERENT act's own alin./lit. — never
    // a real Codul fiscal provision. Confirming neither leaked in as a citable section is the
    // regression guard for docs/reference/fiscal-code-parse-defects.md's "4 ALINEAT + 1 LITERA
    // inside a Notă" explanation of the residual ALINEAT/LITERA gap vs. the real S_ALN/S_LIT counts.
    $quotedFromOrdonanta = findByLabel($result->topLevelSections, 'Art. 46^2 alin. (1)');
    $quotedFromLegea141 = findByLabel($result->topLevelSections, 'Art. 97 lit. c)');
    $article = findByLabelOrFail($result->topLevelSections, 'Art. 46^2');

    expect($quotedFromOrdonanta)->toBeNull()
        ->and($quotedFromLegea141)->toBeNull()
        ->and($article->text)->not->toContain('Termenul de constituire a garanției');
});
