<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\TaxKnowledge\LegislatieJustRoHtmlParserTest;

use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\Exceptions\UnparsableLegislationDocumentException;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;

/**
 * Pure unit tests — no database, no HTTP. The fixture below is a SYNTHETIC FIXTURE: its
 * class-name structure (S_ART/S_ALN/S_LIT/S_NTA/S_PUB and their _TTL/_BDY/_SHORT/_PAR suffixes)
 * was verified against a live legislatie.just.ro fetch on 2026-09-08
 * (docs/handoff/018-legislation-ingestion.md "Sources consulted"), but the legal text content
 * itself ("Denumirea marginala", "Primul alineat...") is invented placeholder text, NOT real
 * Romanian law — never presented as a real citation. See
 * tests/Feature/Domain/TaxKnowledge/LegislatieJustRoLiveFetchTest.php for the real-content proof.
 */
function syntheticLegislationHtml(): string
{
    return <<<'HTML'
        <html>
        <body>
        <div class="articole_wrapper">
        <span class="S_ART" id="id_artA1">
          <span class="S_ART_TTL" id="id_artA1_ttl">Articolul 5</span>
          <span class="S_ART_DEN"><!----></span>
          <span class="S_ART_BDY" id="id_artA1_bdy">
            <span class="S_PAR">Denumirea marginala a articolului</span>
            <span class="S_ALN" id="id_alnA2">
              <span class="S_ALN_TTL" id="id_alnA2_ttl">(1)</span>
              <span class="S_ALN_BDY" id="id_alnA2_bdy">Primul alineat cu o enumerare:<span class="S_LIT" id="id_litA3">
                <span class="S_LIT_TTL" id="id_litA3_ttl">a)</span>
                <span class="S_LIT_BDY" id="id_litA3_bdy">textul literei a</span>
                <span class="S_LIT_SHORT" id="id_litA3_short" style="display:none"> ... </span>
              </span><span class="S_LIT" id="id_litA4">
                <span class="S_LIT_TTL" id="id_litA4_ttl">b)</span>
                <span class="S_LIT_BDY" id="id_litA4_bdy">textul literei b</span>
              </span></span>
            </span>
            <span class="S_ALN" id="id_alnA5">
              <span class="S_ALN_TTL" id="id_alnA5_ttl">(2)</span>
              <span class="S_ALN_BDY" id="id_alnA5_bdy">Al doilea alineat, fara litere.</span>
            </span>
            <span class="S_NTA" id="id_ntaA9">
              <span class="S_NTA_TTL">Nota</span>
              <span id="id_ntaA9_lung"><span class="S_NTA_PAR">Modificat prin Legea de test nr. 1/2020.</span></span>
            </span>
          </span>
        </span>
        <span class="S_ART" id="id_artA10">
          <span class="S_ART_TTL" id="id_artA10_ttl">Articolul UNIC</span>
          <span class="S_ART_DEN"><!----></span>
          <span class="S_ART_BDY" id="id_artA10_bdy">Text simplu fara alineate.</span>
        </span>
        </div>
        <span class="S_PUB"><span class="S_PUB_BDY">MONITORUL OFICIAL nr. 1 din 5 martie 2021</span></span>
        </body>
        </html>
        HTML;
}

it('parses articles, alineate and litere with correct nesting and citation labels', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(syntheticLegislationHtml());

    expect($result->topLevelSections)->toHaveCount(2);

    $article5 = $result->topLevelSections[0];
    expect($article5->sectionType)->toBe(SectionType::ARTICOL)
        ->and($article5->citationLabel)->toBe('Art. 5')
        ->and($article5->text)->toBe('Denumirea marginala a articolului')
        ->and($article5->children)->toHaveCount(2);

    $alineat1 = $article5->children[0];
    expect($alineat1->sectionType)->toBe(SectionType::ALINEAT)
        ->and($alineat1->citationLabel)->toBe('Art. 5 alin. (1)')
        ->and($alineat1->text)->toBe('Primul alineat cu o enumerare:')
        ->and($alineat1->children)->toHaveCount(2);

    $literaA = $alineat1->children[0];
    expect($literaA->sectionType)->toBe(SectionType::LITERA)
        ->and($literaA->citationLabel)->toBe('Art. 5 alin. (1) lit. a)')
        ->and($literaA->text)->toBe('textul literei a')
        ->and($literaA->children)->toBe([]);

    $literaB = $alineat1->children[1];
    expect($literaB->citationLabel)->toBe('Art. 5 alin. (1) lit. b)');

    $alineat2 = $article5->children[1];
    expect($alineat2->citationLabel)->toBe('Art. 5 alin. (2)')
        ->and($alineat2->text)->toBe('Al doilea alineat, fara litere.');
});

it('keeps S_NTA notes out of the text, beside it, and drops S_LIT_SHORT placeholders', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(syntheticLegislationHtml());

    $article5 = $result->topLevelSections[0];

    expect($article5->text)->not->toContain('Nota')
        ->and($article5->text)->not->toContain('Modificat prin Legea de test')
        ->and($article5->notes)->toBe(['Modificat prin Legea de test nr. 1/2020.'])
        ->and($article5->children[0]->children[0]->text)->not->toContain('...');
});

it('handles an article with no alineate as a single flat text node', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(syntheticLegislationHtml());

    $articleUnic = $result->topLevelSections[1];

    expect($articleUnic->citationLabel)->toBe('Art. UNIC')
        ->and($articleUnic->text)->toBe('Text simplu fara alineate.')
        ->and($articleUnic->children)->toBe([]);
});

it('extracts the publication date from the S_PUB_BDY line', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(syntheticLegislationHtml());

    expect($result->publicationDate?->format('Y-m-d'))->toBe('2021-03-05');
});

it('produces a stable text_hash for a section', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(syntheticLegislationHtml());

    $article5 = $result->topLevelSections[0];

    expect($article5->textHash())->toBe(hash('sha256', $article5->text))
        ->and($article5->textHash())->toHaveLength(64);
});

it('assigns sequential ordinals to top-level sections and to each section\'s own children', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(syntheticLegislationHtml());

    expect($result->topLevelSections[0]->ordinal)->toBe(1)
        ->and($result->topLevelSections[1]->ordinal)->toBe(2)
        ->and($result->topLevelSections[0]->children[0]->ordinal)->toBe(1)
        ->and($result->topLevelSections[0]->children[1]->ordinal)->toBe(2);
});

it('rejects empty content', function (): void {
    (new LegislatieJustRoHtmlParser())->parse('   ');
})->throws(UnparsableLegislationDocumentException::class);

it('returns no sections and no publication date for a document with no recognised structure', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse('<html><body><p>Plain content, nothing to see here.</p></body></html>');

    expect($result->topLevelSections)->toBe([])
        ->and($result->publicationDate)->toBeNull();
});

/*
 * Formatting (parser 1.2.0): what the text keeps of the page.
 */

function parsedAlineat(string $body): \Kern\Legislation\Parsing\ParsedSection
{
    $html = <<<HTML
        <html><body>
        <span class="S_ART"><span class="S_ART_TTL">Articolul 64</span><span class="S_ART_BDY">
          <span class="S_ALN"><span class="S_ALN_TTL">(1)</span><span class="S_ALN_BDY">{$body}</span></span>
        </span></span>
        </body></html>
        HTML;

    $article = (new LegislatieJustRoHtmlParser())->parse($html)->topLevelSections[0];

    return $article->children[0];
}

it('keeps a line break between the blocks of a provision', function (): void {
    $alineat = parsedAlineat('<span class="S_PAR">Impozitul se calculează astfel:</span><span class="S_PAR">(la 01-01-2025, Alineatul (1) a fost modificat de Punctul 3.)</span>');

    expect($alineat->text)->toBe("Impozitul se calculează astfel:\n(la 01-01-2025, Alineatul (1) a fost modificat de Punctul 3.)");
});

it('keeps a table as rows and cells, in its place in the text', function (): void {
    $alineat = parsedAlineat(
        '<span class="S_PAR">Impozitul se calculează potrivit tabelului:</span>'
        . '<table><tr><td>Tranșe de venit brut <br/>- lei -</td><td>Impozit</td></tr>'
        . '<tr><td>până la 66.750, inclusiv</td><td>1%</td></tr></table>'
        . '<span class="S_PAR">Plafonul se actualizează anual.</span>',
    );

    expect($alineat->text)->toBe("Impozitul se calculează potrivit tabelului:\n[[table:1]]\nPlafonul se actualizează anual.")
        ->and($alineat->tables)->toBe([[['Tranșe de venit brut - lei -', 'Impozit'], ['până la 66.750, inclusiv', '1%']]])
        // What is searched and quoted has the table written out.
        ->and($alineat->fullText())->toContain('până la 66.750, inclusiv | 1%');
});

it('makes a dash item a provision of its own, cited by its position', function (): void {
    $alineat = parsedAlineat(
        '<span class="S_PAR">Procedura cuprinde:</span>'
        . '<span class="S_LIN"><span class="S_LIN_TTL">– </span><span class="S_LIN_BDY">plan de rambursare a datoriilor;</span></span>'
        . '<span class="S_LIN"><span class="S_LIN_TTL">– </span><span class="S_LIN_BDY">lichidare de active;</span></span>',
    );

    expect($alineat->text)->toBe('Procedura cuprinde:')
        ->and(array_map(static fn ($child) => [$child->sectionType, $child->citationLabel, $child->text], $alineat->children))
        ->toBe([
            [SectionType::LINIUTA, 'Art. 64 alin. (1) liniuța 1', 'plan de rambursare a datoriilor;'],
            [SectionType::LINIUTA, 'Art. 64 alin. (1) liniuța 2', 'lichidare de active;'],
        ]);
});

it('keeps the table of contents, and which heading each article stands under', function (): void {
    $html = <<<'HTML'
        <html><body>
        <span class="S_TTL"><span class="S_TTL_TTL">Titlul IV</span><span class="S_TTL_DEN">Impozitul pe venit</span><span class="S_TTL_BDY">
          <span class="S_CAP"><span class="S_CAP_TTL">Capitolul IV</span><span class="S_CAP_DEN">Venituri din cedarea folosinței bunurilor</span><span class="S_CAP_BDY">
            <span class="S_ART"><span class="S_ART_TTL">Articolul 83</span><span class="S_ART_BDY"><span class="S_PAR">Definirea veniturilor</span></span></span>
          </span></span>
          <span class="S_CAP"><span class="S_CAP_TTL">Capitolul V</span><span class="S_CAP_DEN">Venituri din investiții</span><span class="S_CAP_BDY">
            <span class="S_SEC"><span class="S_SEC_TTL">Secţiunea 1</span><span class="S_SEC_DEN">Dispoziții generale</span><span class="S_SEC_BDY">
              <span class="S_ART"><span class="S_ART_TTL">Articolul 91</span><span class="S_ART_BDY"><span class="S_PAR">Definirea veniturilor</span></span></span>
            </span></span>
          </span></span>
        </span></span>
        </body></html>
        HTML;

    $parsed = (new LegislatieJustRoHtmlParser())->parse($html);

    expect(array_map(static fn ($node) => [$node->level->value, $node->path, $node->name, $node->ordinal, $node->parentIndex], $parsed->outline))
        ->toBe([
            ['TITLU', 'Titlul IV', 'Impozitul pe venit', 1, null],
            ['CAPITOL', 'Titlul IV Capitolul IV', 'Venituri din cedarea folosinței bunurilor', 1, 0],
            ['CAPITOL', 'Titlul IV Capitolul V', 'Venituri din investiții', 2, 0],
            ['SECTIUNE', 'Titlul IV Capitolul V Secţiunea 1', 'Dispoziții generale', 1, 2],
        ])
        // Cited as before — a heading is never part of a citation.
        ->and(array_map(static fn ($section) => [$section->citationLabel, $section->outlineIndex], $parsed->topLevelSections))
        ->toBe([['Art. 83', 1], ['Art. 91', 3]]);
});
