<?php

declare(strict_types=1);

namespace Kern\Legislation\Tests\Unit\LegislatieJustRoLegacyMarkupTest;

use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;
use Kern\Legislation\Parsing\ParsedSection;
use RuntimeException;

/**
 * One law, three markups: the same articles of the Codul fiscal as the portal serves them in its
 * 2016-01-01, 2016-03-25 and 2026-08-08 consolidations — verbatim extracts of arts. 7, 26, 105 and 170
 * (`tests/Fixtures/TaxKnowledge/Legislatie/codul-fiscal-*-art-7-26-105-170.html`).
 *
 * The 2016-01-01 page marks every paragraph as a letter with its label in the text, writes points
 * and dashes as plain lines and draws tables in box characters; 2016-03-25 has paragraphs but still
 * the plain lines and the drawings; 2026-08-08 marks everything. A provision's history is only as
 * good as all three reading with the same citations.
 */

/** @return array<string, ParsedSection> citation => section */
function sectionsOf(string $consolidation): array
{
    $path = packageFixture("Legislatie/codul-fiscal-{$consolidation}-art-7-26-105-170.html");
    $html = file_get_contents($path);

    if ($html === false) {
        throw new RuntimeException("Could not read fixture: {$path}");
    }

    $found = [];
    $walk = static function (ParsedSection $section) use (&$walk, &$found): void {
        $found[$section->citationLabel] = $section;

        foreach ($section->children as $child) {
            $walk($child);
        }
    };

    foreach ((new LegislatieJustRoHtmlParser())->parse($html)->topLevelSections as $section) {
        $walk($section);
    }

    return $found;
}

it('reads a paragraph written as a letter with its label in the text', function (): void {
    $alineat = sectionsOf('2016-01-01')['Art. 170 alin. (1)'] ?? null;

    expect($alineat?->sectionType)->toBe(SectionType::ALINEAT)
        ->and($alineat?->text)->toStartWith('Pentru persoanele fizice care realizează venituri')
        ->and(array_key_exists('Art. 170 lit. 1)', sectionsOf('2016-01-01')))->toBeFalse();
});

it('reads the old forms with the same citations as the new', function (string $consolidation): void {
    $old = array_keys(sectionsOf($consolidation));
    $new = array_keys(sectionsOf('2026-08-08'));

    // The law grew between 2016 and 2026; everything that was there in 2016 is still cited the same.
    expect(array_values(array_diff($old, $new)))->toBe([]);
})->with(['2016-01-01', '2016-03-25']);

it('nests points under their letter and dashes under their point', function (string $consolidation): void {
    $sections = sectionsOf($consolidation);

    expect($sections['Art. 26 alin. (1) lit. c) pct. 1.']->sectionType ?? null)->toBe(SectionType::PUNCT)
        ->and($sections['Art. 26 alin. (1) lit. j) pct. 1. liniuța 1']->text ?? null)->toBe('plan de rambursare a datoriilor;')
        ->and($sections['Art. 26 alin. (1) lit. j) pct. 1. liniuța 3']->text ?? null)->toBe('procedură simplificată;');
})->with(['2016-01-01', '2016-03-25', '2026-08-08']);

it('reads a table drawn in box characters as the table the new markup has', function (): void {
    $drawn = sectionsOf('2016-01-01')['Art. 105 alin. (2)']->tables[0] ?? [];
    $marked = sectionsOf('2026-08-08')['Art. 105 alin. (2)']->tables[0] ?? [];

    expect(array_slice($drawn, 0, 2))->toBe([['Nr. crt.', 'Produse vegetale', 'Suprafaţă'], ['1.', 'Cereale', 'până la 2 ha']])
        // The new markup stacks "Nr." and "crt." as two paragraphs of the cell: each kept on its line.
        ->and(array_slice($marked, 0, 2))->toBe([["Nr.\ncrt.", 'Produse vegetale', 'Suprafață'], ['1.', 'Cereale', 'până la 2 ha']]);
});

it('reads the definitions the way the new markup nests them: points holding points, letters and paragraphs', function (string $consolidation): void {
    $sections = sectionsOf($consolidation);

    expect(array_keys(array_intersect_key($sections, array_flip([
        'Art. 7 pct. 3. pct. 3.1.',
        'Art. 7 pct. 7. lit. a)',
        'Art. 7 pct. 7. lit. e)',
    ]))))->toBe(['Art. 7 pct. 3. pct. 3.1.', 'Art. 7 pct. 7. lit. a)', 'Art. 7 pct. 7. lit. e)'])
        // Two points in a row stay two points: "4." is not a point of "3.".
        ->and(array_key_exists('Art. 7 pct. 4.', $sections))->toBeTrue()
        ->and(array_key_exists('Art. 7 pct. 3. pct. 4.', $sections))->toBeFalse();
})->with(['2016-01-01', '2016-03-25', '2026-08-08']);

it('keeps the portal\'s notes on the provision they stand in, out of its text', function (): void {
    $article = sectionsOf('2026-08-08')['Art. 170'];

    expect($article->notes)->toHaveCount(2)
        ->and($article->notes[0])->toStartWith('Conform literei b) a alineatului (2) al articolului XIII din LEGEA nr. 239')
        ->and($article->notes[0])->toEndWith('se aplică începând cu veniturile aferente anului 2026.')
        ->and($article->text)->not->toContain('LEGEA nr. 239');
});
