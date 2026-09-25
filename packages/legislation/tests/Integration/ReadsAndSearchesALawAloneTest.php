<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Retrieval\Indexing\IndexDocumentVersionForRetrieval;
use Kern\Legislation\Retrieval\Query\HybridLegislationSearch;
use Kern\Legislation\Retrieval\Query\HybridSearchQuery;

/**
 * A law read from the portal and searched, in an application with no tax code in it.
 *
 * This is the proof `docs/adr/0016` asks for by name. Everything below happens with three packages
 * installed — audit, platform, legislation — and no taxpayer, no tax engine, no fiscal parameter and
 * no `tax_subjects` table anywhere. If reading the law had kept a single line about Romanian tax, this
 * file could not exist.
 *
 * The portal's markup is the real one, structurally: the SOAP search answer, the base page pointing at
 * the consolidated document, the `istoric_fa` list of consolidations, and the `S_TTL`/`S_CAP`/`S_ART`
 * spans a consolidation's text is written in. The words in it are invented.
 */
function portalAnswering(): void
{
    $consolidations = '<div id="istoric_fa">'
        . "<a title='Consolidarea din 01.01.2024' href='~/../../../Public/DetaliiDocument/2002'>01.01.2024</a><br/>"
        . "<a title='Consolidarea din 01.01.2020' href='~/../../../Public/DetaliiDocument/2001'>01.01.2020</a>"
        . '</div>';

    $page = static fn (string $days): string => <<<HTML
        <html><body><div class="content_forma_act">
        <span class="S_TTL"><span class="S_TTL_TTL">Titlul II</span><span class="S_TTL_DEN">Concediul de odihnă</span><span class="S_TTL_BDY">
          <span class="S_CAP"><span class="S_CAP_TTL">Capitolul I</span><span class="S_CAP_DEN">Durata concediului</span><span class="S_CAP_BDY">
            <span class="S_ART"><span class="S_ART_TTL">Articolul 145</span><span class="S_ART_BDY">
              <span class="S_ALN"><span class="S_ALN_TTL">(1)</span><span class="S_ALN_BDY"><span class="S_PAR">Durata minimă a concediului de odihnă anual este de {$days} zile lucrătoare.</span></span></span>
            </span></span>
          </span></span>
        </span></span>
        </div></body></html>
        HTML;

    $pages = ['2001' => $page('20'), '2002' => $page('24')];

    Http::fake(static function (HttpRequest $request) use ($consolidations, $pages) {
        $url = $request->url();

        if (str_contains($url, '/apiws/')) {
            $action = $request->header('SOAPAction')[0] ?? '';

            if (str_contains($action, 'GetToken')) {
                return Http::response(soap('<GetTokenResponse xmlns="http://tempuri.org/"><GetTokenResult>token-1</GetTokenResult></GetTokenResponse>'));
            }

            $act = str_contains($request->body(), '<fws:NumarPagina>1</fws:NumarPagina>')
                ? '<a:Legi><a:DataVigoare>2003-03-01</a:DataVigoare><a:Emitent>Parlamentul</a:Emitent>'
                    . '<a:LinkHtml>http://legislatie.just.ro/Public/DetaliiDocument/500000</a:LinkHtml><a:Numar>53</a:Numar>'
                    . '<a:Publicatie>Monitorul Oficial</a:Publicatie><a:Text>…</a:Text><a:TipAct>LEGE</a:TipAct>'
                    . '<a:Titlu>LEGE nr. 53 din 24 ianuarie 2003 privind Codul muncii EMITENT: PARLAMENTUL '
                    . 'PUBLICAT ÎN: MONITORUL OFICIAL nr. 72 din 5 februarie 2003</a:Titlu></a:Legi>'
                : '';

            return Http::response(soap('<SearchResponse xmlns="http://tempuri.org/"><SearchResult xmlns:a="http://schemas.datacontract.org/2004/07/FreeWebService">' . $act . '</SearchResult></SearchResponse>'));
        }

        if (str_contains($url, '/Public/DetaliiDocument/500000')) {
            return Http::response('<html><body><div id="div_Formaconsolidata" class="content_forma_act">'
                . '<span class="S_PAR"><span class="S_REF"><A href="~/../../../Public/DetaliiDocumentAfis/500001">COD MUNCII 24/01/2003</A></span></span>'
                . '</div></body></html>');
        }

        if (str_contains($url, '/Public/DetaliiDocumentAfis/500001')) {
            return Http::response("<html><body>{$consolidations}</body></html>");
        }

        foreach ($pages as $id => $html) {
            if (str_ends_with($url, "/Public/DetaliiDocument/{$id}")) {
                return Http::response($html);
            }
        }

        return Http::response('', 404);
    });
}

function soap(string $inner): string
{
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $inner . '</s:Body></s:Envelope>';
}

it('has no tax anything to lean on', function (): void {
    foreach (['ext_official_sources', 'ext_source_documents', 'ext_source_document_versions',
        'ext_source_sections', 'ext_source_section_chunks', 'ext_section_histories'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing {$table}");
    }

    foreach (['tax_subjects', 'fiscal_parameters', 'tax_rules', 'financial_transactions'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} should not exist here");
    }
});

it('reads a law, dates its consolidations, and answers a search about what it said on a date', function (): void {
    portalAnswering();

    $started = app(SyncLegislationDocument::class)('lege', 53, 2003);

    expect([$started->listed, $started->toRead])->toBe([2, 2]);

    $law = SourceDocument::query()->where('external_identifier', 'LEGE 53/2003')->sole();

    expect($law->title)->toBe('LEGE nr. 53 din 24 ianuarie 2003 privind Codul muncii');

    // Each consolidation is in force until the day before the next begins; the newest has no end.
    $versions = SourceDocumentVersion::query()
        ->where('source_document_id', $law->id)
        ->orderBy('effective_from')
        ->get();

    expect($versions->map(static fn (SourceDocumentVersion $v): array => [
        $v->effective_from?->format('Y-m-d'),
        $v->effective_until?->format('Y-m-d'),
    ])->all())->toBe([['2020-01-01', '2023-12-31'], ['2024-01-01', null]]);

    // The same article, twice, saying different things.
    $article = SourceSection::query()
        ->whereIn('document_version_id', $versions->pluck('id'))
        ->where('citation_label', 'like', '%145%')
        ->where('section_type', 'ALINEAT')
        ->orderBy('effective_from')
        ->get();

    expect($article)->toHaveCount(2)
        ->and($article[0]->text)->toContain('20 zile')
        ->and($article[1]->text)->toContain('24 zile');

    foreach ($versions as $version) {
        app(IndexDocumentVersionForRetrieval::class)($version->id);
    }

    // And the search answers with the text that was in force on the date asked about, not the newest.
    $found = app(HybridLegislationSearch::class)->search(new HybridSearchQuery(
        queryText: 'durata minimă a concediului de odihnă',
        asOf: new DateTimeImmutable('2021-06-15'),
    ));

    $texts = array_map(static fn (object $r): string => $r->text, $found->confirmed);

    expect($found->confirmed)->not->toBeEmpty()
        ->and(implode(' | ', $texts))->toContain('20 zile')
        ->and(implode(' | ', $texts))->not->toContain('24 zile');
});
