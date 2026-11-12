<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\LawTextTest;

use DateTimeImmutable;
use Illuminate\Testing\TestResponse;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Parsing\ParsedLegislationDocument;
use Kern\Legislation\Parsing\ParsedSection;
use Kern\Legislation\Repositories\OfficialSourceRepository;
use Kern\Legislation\Services\LegislationSectionPersister;
use Kern\Platform\Identity\Models\User;
use Tests\TestCase;

/**
 * An order stored with these provisions, and its text as the law page asks for it. Made up.
 *
 * @param  list<ParsedSection>  $sections
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function text(TestCase $test, array $sections): TestResponse
{
    $order = SourceDocument::query()->create([
        'official_source_id' => app(OfficialSourceRepository::class)->idForCode('LEGISLATIE'),
        'document_type' => 'ORDIN', 'external_identifier' => 'ORDIN 1/2026', 'act_number' => '1', 'title' => 'ORDIN nr. 1 privind exemplul',
        'canonical_url' => 'https://legislatie.just.ro/Public/DetaliiDocument/1',
    ]);
    $version = SourceDocumentVersion::factory()->create(['source_document_id' => $order->id]);
    app(LegislationSectionPersister::class)->persist($version->id, new ParsedLegislationDocument(null, $sections), effectiveFrom: new DateTimeImmutable('2026-01-01'));

    return $test->actingAs(User::factory()->create())->getJson("/api/v1/legislation/laws/{$order->id}/text?as_of=2026-06-01")->assertOk();
}

it('heads an annex’s article by its own number, and the preamble and the signature by name', function (): void {
    // Shaped as an order with a prospectus in its annex.
    $annexArticle = new ParsedSection(SectionType::ARTICOL, 'Articolul 2', 'ANEXĂ Art. 2', 1, 'Exemplul de articol al anexei.', []);

    $text = text($this, [
        new ParsedSection(SectionType::PREAMBUL, '', 'Preambul', 1, "Având în vedere exemplul:\nemite următorul ordin:", []),
        new ParsedSection(SectionType::ARTICOL, 'Articolul 1', 'Art. 1', 2, 'Exemplul de articol.', []),
        new ParsedSection(SectionType::SEMNATURA, '', 'Semnătura', 3, "Ministrul exemplului\nNr. 1.", []),
        new ParsedSection(SectionType::ANEXA, 'ANEXĂ', 'ANEXĂ', 4, 'Exemplul de prospect', [$annexArticle]),
    ]);

    expect($text->json('sections.*.label'))->toBe(['Preambul', 'Articolul 1', 'Semnătura', 'ANEXĂ', 'Articolul 2'])
        ->and($text->json('sections.4.citation'))->toBe('ANEXĂ Art. 2');
});

it('gives each table its merged cells beside its rows, and none to a table that merges none', function (): void {
    // A schedule shaped as a prospectus prints one, and a table that merges nothing.
    $merged = [['row' => 0, 'cell' => 0, 'colspan' => 3, 'rowspan' => 1], ['row' => 2, 'cell' => 0, 'colspan' => 2, 'rowspan' => 2]];
    $schedule = [['Cod emisiune 0001'], ['1', 'exemplul de perioadă', '1 ianuarie 2027'], ['Plata valorii nominale', 'Data de plată'], ['1 ianuarie 2027']];

    $text = text($this, [
        new ParsedSection(SectionType::ARTICOL, 'Articolul 1', 'Art. 1', 1, "[[table:1]]\n[[table:2]]", [], [$schedule, [['a', 'b']]], tableSpans: [$merged, []]),
        new ParsedSection(SectionType::ARTICOL, 'Articolul 2', 'Art. 2', 2, '[[table:1]]', [], [[['c', 'd']]], tableSpans: [[]]),
    ]);

    expect($text->json('sections.0.tables.0'))->toBe($schedule)
        ->and($text->json('sections.0.table_spans'))->toBe([$merged, []])
        ->and($text->json('sections.1.table_spans'))->toBe([]);
});
