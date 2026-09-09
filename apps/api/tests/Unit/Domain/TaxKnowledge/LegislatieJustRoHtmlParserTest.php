<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\TaxKnowledge\LegislatieJustRoHtmlParserTest;

use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\Parsing\Exceptions\UnparsableLegislationDocumentException;
use App\Domain\TaxKnowledge\Parsing\LegislatieJustRoHtmlParser;

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

it('excludes S_NTA editorial notes and S_LIT_SHORT placeholders from extracted text', function (): void {
    $result = (new LegislatieJustRoHtmlParser())->parse(syntheticLegislationHtml());

    $article5 = $result->topLevelSections[0];

    expect($article5->text)->not->toContain('Nota')
        ->and($article5->text)->not->toContain('Modificat prin Legea de test')
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
