<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\SyncLegislationDocumentTest;

use App\Domain\Identity\Models\User;
use App\Domain\TaxKnowledge\Actions\SyncLegislationDocument;
use App\Domain\TaxKnowledge\Enums\SourceDocumentVersionStatus;
use App\Domain\TaxKnowledge\Legislatie\Exceptions\PortalAnswerException;
use App\Domain\TaxKnowledge\Models\SectionHistory;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider;
use App\Domain\TaxKnowledge\Services\LegislationTemporalQuery;
use DateTimeImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A law read from a faked legislatie.just.ro, end to end: found through the web service, followed to
 * its consolidations, each read, dated against the next, and its provisions' history built.
 *
 * The pages use the portal's real markup and structure, checked against the Codul fiscal on
 * 2026-09-22 — the SOAP answer's shape, the base page linking to `DetaliiDocumentAfis`, the
 * `istoric_fa` list — with invented text. Three consolidations: the salary multiple in art. 170
 * changes in the second, art. 5 is gone in the third.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'sources.storage.disk' => 's3',
        'sources.storage.prefix' => 'sources',
        'legislation.sync.retry_seconds' => [],
        // Off whatever the machine's `.env` says: these tests are about reading the law, and the
        // embedding model is a separate service they must not reach.
        'ai.embeddings.enabled' => false,
    ]);
    app()->forgetInstance(EmbeddingProvider::class);
});

function soapAct(string $type, string $number, string $title, string $link): string
{
    return '<a:Legi><a:DataVigoare>2016-01-01</a:DataVigoare><a:Emitent>Parlamentul</a:Emitent>'
        . "<a:LinkHtml>{$link}</a:LinkHtml><a:Numar>{$number}</a:Numar><a:Publicatie>Monitorul Oficial</a:Publicatie>"
        . "<a:Text>…</a:Text><a:TipAct>{$type}</a:TipAct><a:Titlu>{$title}</a:Titlu></a:Legi>";
}

function soapEnvelope(string $inner): string
{
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $inner . '</s:Body></s:Envelope>';
}

function consolidationPage(string $salaries, bool $withArticle5): string
{
    $article5 = $withArticle5
        ? '<span class="S_ART"><span class="S_ART_TTL">Articolul 5</span><span class="S_ART_BDY"><span class="S_PAR">Dispoziție tranzitorie.</span></span></span>'
        : '';

    return <<<HTML
        <html><body><div class="content_forma_act">
        <span class="S_TTL"><span class="S_TTL_TTL">Titlul V</span><span class="S_TTL_DEN">Contribuții sociale obligatorii</span><span class="S_TTL_BDY">
          <span class="S_CAP"><span class="S_CAP_TTL">Capitolul III</span><span class="S_CAP_DEN">Contribuția de asigurări sociale de sănătate</span><span class="S_CAP_BDY">
            <span class="S_ART"><span class="S_ART_TTL">Articolul 170</span><span class="S_ART_BDY">
              <span class="S_ALN"><span class="S_ALN_TTL">(1)</span><span class="S_ALN_BDY"><span class="S_PAR">Baza de calcul este de {$salaries} salarii minime brute pe țară.</span></span></span>
            </span></span>
          </span></span>
          {$article5}
        </span></span>
        </div></body></html>
        HTML;
}

/**
 * The portal, answering every page the sync reads.
 *
 * @param  array<int, int>  $failing  consolidation id => the status its page answers with
 */
function portal(array $failing = []): void
{
    $afis = '<div id="istoric_fa">'
        . "<a title='Consolidarea din 01.08.2025' href='~/../../../Public/DetaliiDocument/1003'>01.08.2025</a><br/>"
        . "<a title='Consolidarea din 01.01.2021' href='~/../../../Public/DetaliiDocument/1002'>01.01.2021</a><br/>"
        . "<a title='Consolidarea din 01.01.2016' href='~/../../../Public/DetaliiDocument/1001'>01.01.2016</a>"
        . '</div>';

    $pages = [
        '1001' => consolidationPage('6', true),
        '1002' => consolidationPage('12', true),
        '1003' => consolidationPage('12', false),
    ];

    Http::fake(static function (HttpRequest $request) use ($afis, $pages, $failing) {
        $url = $request->url();

        if (str_contains($url, '/apiws/')) {
            $action = $request->header('SOAPAction')[0] ?? '';

            if (str_contains($action, 'GetToken')) {
                return Http::response(soapEnvelope('<GetTokenResponse xmlns="http://tempuri.org/"><GetTokenResult>token-1</GetTokenResult></GetTokenResponse>'));
            }

            // Page 0 holds both acts numbered 227 that year; any later page is empty.
            $acts = str_contains($request->body(), '<fws:NumarPagina>0</fws:NumarPagina>')
                ? soapAct('DECRET', '227', 'DECRET nr. 227 din 23 ianuarie 2015 pentru numirea unui procuror EMITENT: PREŞEDINTELE ROMÂNIEI PUBLICAT ÎN: MONITORUL OFICIAL nr. 63 din 26 ianuarie 2015', 'http://legislatie.just.ro/Public/DetaliiDocument/164954')
                    . soapAct('LEGE', '227', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal EMITENT: PARLAMENTUL PUBLICAT ÎN: MONITORUL OFICIAL nr. 688 din 10 septembrie 2015', 'http://legislatie.just.ro/Public/DetaliiDocument/171280')
                : '';

            return Http::response(soapEnvelope('<SearchResponse xmlns="http://tempuri.org/"><SearchResult xmlns:a="http://schemas.datacontract.org/2004/07/FreeWebService">' . $acts . '</SearchResult></SearchResponse>'));
        }

        if (str_contains($url, '/Public/DetaliiDocument/171280')) {
            return Http::response('<html><body><a href="~/../../../Public/DetaliiDocumentAfis/171282">COD FISCAL</a></body></html>');
        }

        if (str_contains($url, '/Public/DetaliiDocumentAfis/171282')) {
            return Http::response("<html><body>{$afis}</body></html>");
        }

        foreach ($pages as $id => $html) {
            if (str_ends_with($url, "/Public/DetaliiDocument/{$id}")) {
                return array_key_exists($id, $failing) ? Http::response('<html>Error</html>', $failing[$id]) : Http::response($html);
            }
        }

        return Http::response('', 404);
    });
}

function fiscalCode(): SourceDocument
{
    return SourceDocument::query()->where('external_identifier', 'LEGE 227/2015')->sole();
}

it('reads a law with every consolidation, each dated until the day before the next', function (): void {
    portal();

    $started = app(SyncLegislationDocument::class)('lege', 227, 2015);
    $law = fiscalCode();

    expect([$started->listed, $started->toRead])->toBe([3, 3])
        ->and($law->title)->toBe('LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal')
        ->and($law->document_type)->toBe('LEGE')
        ->and($law->act_type_label)->toBe('LEGE')
        ->and($law->act_number)->toBe('227')
        ->and($law->act_date?->format('Y-m-d'))->toBe('2015-09-08')
        ->and($law->official_journal_number)->toBe('688')
        ->and($law->publication_date?->format('Y-m-d'))->toBe('2015-09-10')
        ->and($law->in_force_from?->format('Y-m-d'))->toBe('2016-01-01')
        ->and($law->issuer)->toBe('PARLAMENTUL')
        ->and($law->portal_document_id)->toBe('171280')
        ->and($law->consolidated_document_id)->toBe('171282')
        ->and($law->last_synced_at)->not->toBeNull();

    $versions = SourceDocumentVersion::query()->where('source_document_id', $law->id)->orderBy('effective_from')->get();

    expect($versions->map(static fn (SourceDocumentVersion $v): array => [
        $v->consolidation_ref,
        $v->effective_from?->format('Y-m-d'),
        $v->effective_until?->format('Y-m-d'),
        $v->status,
    ])->all())->toBe([
        ['1001', '2016-01-01', '2020-12-31', SourceDocumentVersionStatus::PARSED],
        ['1002', '2021-01-01', '2025-07-31', SourceDocumentVersionStatus::PARSED],
        ['1003', '2025-08-01', null, SourceDocumentVersionStatus::PARSED],
    ]);

    // The day a consolidation begins belongs to it alone.
    expect(app(LegislationTemporalQuery::class)->versionInForceOn($law->id, new DateTimeImmutable('2021-01-01'))->id)
        ->toBe($versions->get(1)?->id);
});

it('keeps each provision\'s wording as stretches of time, a new one only when the words change', function (): void {
    portal();

    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();

    $stretches = static fn (string $citation): array => SectionHistory::query()
        ->where('source_document_id', $law->id)
        ->where('citation_label', $citation)
        ->orderBy('version_index')
        ->get()
        ->map(static fn (SectionHistory $h): array => [$h->valid_from->format('Y-m-d'), $h->valid_until?->format('Y-m-d'), $h->snapshot_count])
        ->all();

    expect($stretches('Art. 170 alin. (1)'))->toBe([
        ['2016-01-01', '2020-12-31', 1],
        // Unchanged in the third consolidation: one stretch, seen twice.
        ['2021-01-01', null, 2],
    ])
        // Gone from the third consolidation.
        ->and($stretches('Art. 5'))->toBe([['2016-01-01', '2025-07-31', 2]]);
});

it('reads only what is new the second time', function (): void {
    portal();

    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $again = app(SyncLegislationDocument::class)('LEGE', 227, 2015);

    expect($again->toRead)->toBe(0)
        ->and(SourceDocumentVersion::query()->count())->toBe(3);
});

it('marks a consolidation it cannot read, carries on, and names the gap', function (): void {
    portal([1002 => 404]);

    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();

    expect(collect($law->consolidations)->firstWhere('ref', '1002')['failed'] ?? false)->toBeTrue()
        ->and(SourceDocumentVersion::query()->where('source_document_id', $law->id)->pluck('consolidation_ref')->sort()->values()->all())->toBe(['1001', '1003']);

    // The first version now reaches to the third: its stretch crosses the unread consolidation.
    $first = SectionHistory::query()->where('source_document_id', $law->id)->where('citation_label', 'Art. 170 alin. (1)')->orderBy('version_index')->first();

    expect($first?->coverage_complete)->toBeFalse();
});

it('reads from a date, with the consolidation in force on it', function (): void {
    portal();

    $started = app(SyncLegislationDocument::class)('LEGE', 227, 2015, new DateTimeImmutable('2022-06-01'));

    expect($started->listed)->toBe(2)
        ->and(SourceDocumentVersion::query()->pluck('consolidation_ref')->sort()->values()->all())->toBe(['1002', '1003']);
});

it('refuses rather than guesses when no act matches', function (): void {
    portal();

    expect(fn () => app(SyncLegislationDocument::class)('ORDONANTA', 227, 2015))->toThrow(PortalAnswerException::class);
});

it('shows the law, its text on a date, and a provision\'s history', function (): void {
    bootstrapIdentityCryptoForTests();
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/legislation/laws')
        ->assertOk()
        ->assertJsonPath('laws.0.title', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal')
        ->assertJsonPath('laws.0.consolidations_read', 3)
        ->assertJsonPath('laws.0.official_journal_number', '688');

    $this->actingAs($user)->getJson("/api/v1/legislation/laws/{$law->id}")
        ->assertOk()
        ->assertJsonPath('law.consolidations.0.date', '2025-08-01')
        ->assertJsonCount(3, 'law.consolidations');

    $text = $this->actingAs($user)->getJson("/api/v1/legislation/laws/{$law->id}/text?as_of=2019-05-01")->assertOk();

    expect($text->json('effective_from'))->toBe('2016-01-01')
        ->and($text->json('sections.0.label'))->toBe('Articolul 170')
        // The headings it stands under, outermost first.
        ->and($text->json('sections.0.headings.*.label'))->toBe(['Titlul V', 'Capitolul III'])
        ->and($text->json('sections.1.text'))->toContain('6 salarii');

    $this->actingAs($user)->getJson("/api/v1/legislation/laws/{$law->id}/text?as_of=2010-01-01")->assertNotFound();

    // The table of contents: the chapter spans only art. 170; the title reaches art. 5, which stands
    // under it outside any chapter.
    $outline = $this->actingAs($user)->getJson("/api/v1/legislation/laws/{$law->id}/outline?as_of=2019-05-01")->assertOk();

    expect($outline->json('headings.*.path'))->toBe(['Titlul V', 'Titlul V Capitolul III'])
        ->and($outline->json('headings.*.name'))->toBe(['Contribuții sociale obligatorii', 'Contribuția de asigurări sociale de sănătate'])
        ->and($outline->json('headings.*.first_citation'))->toBe(['Art. 170', 'Art. 170'])
        ->and($outline->json('headings.*.last_citation'))->toBe(['Art. 5', 'Art. 170'])
        ->and($outline->json('headings.*.page'))->toBe([1, 1]);

    $history = $this->actingAs($user)
        ->getJson("/api/v1/legislation/laws/{$law->id}/history?citation=" . rawurlencode('Art. 170 alin. (1)'))
        ->assertOk();

    expect($history->json('history.0.valid_from'))->toBe('2021-01-01')
        ->and($history->json('history.0.text'))->toContain('12 salarii')
        ->and($history->json('history.1.text'))->toContain('6 salarii');
});

it('is behind authentication', function (): void {
    $this->getJson('/api/v1/legislation/laws')->assertUnauthorized();
});
