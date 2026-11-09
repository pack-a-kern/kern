<?php

declare(strict_types=1);

namespace Kern\Legislation\Tests\Unit\LegislatieJustRoHtmlParserAnnexArticlesTest;

use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;
use Kern\Legislation\Parsing\ParsedSection;

/**
 * An order whose annex numbers its own articles from 1: the Ministry of Finance's Tezaur prospectus,
 * ORDIN 1.324/2026, as legislatie.just.ro serves it (public, the Official Gazette's text).
 *
 * The annex's Art. 1–3 used to share their labels with the order's own Art. 1–3 and were dropped as
 * ambiguous — Art. 2 is the table of the issues the prospectus launches.
 */
function tezaurProspectus(): array
{
    $html = (string) file_get_contents(__DIR__ . '/../Fixtures/Legislatie/ordin-1324-2026-prospect-tezaur.html');
    $parsed = (new LegislatieJustRoHtmlParser())->parse($html);

    return [$parsed, array_values(array_filter($parsed->topLevelSections, fn (ParsedSection $s): bool => $s->sectionType === SectionType::ANEXA))[0]];
}

it('keeps every article of an annex, cited within it, beside the order’s own', function (): void {
    [$parsed, $annex] = tezaurProspectus();

    expect($parsed->ambiguousCitationLabels)->toBe([])
        ->and(array_values(array_map(
            fn (ParsedSection $s): string => $s->citationLabel,
            array_filter($parsed->topLevelSections, fn (ParsedSection $s): bool => $s->sectionType === SectionType::ARTICOL),
        )))->toBe(['Art. 1', 'Art. 2', 'Art. 3'])
        ->and(array_map(fn (ParsedSection $s): string => $s->citationLabel, array_slice($annex->children, 0, 3)))->toBe(["{$annex->citationLabel} Art. 1", "{$annex->citationLabel} Art. 2", "{$annex->citationLabel} Art. 3"])
        ->and($annex->children)->toHaveCount(17);
});

it('keeps the annex article holding the table of issues, with its table', function (): void {
    [, $annex] = tezaurProspectus();
    $issues = $annex->children[1];

    expect($issues->children[0]->tables)->not->toBe([])
        ->and(json_encode($issues->children[0]->tables, JSON_UNESCAPED_UNICODE))->toContain('0228');
});
