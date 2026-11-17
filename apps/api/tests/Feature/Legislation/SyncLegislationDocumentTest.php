<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\SyncLegislationDocumentTest;

use DateTimeImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\PayoutSource;
use Kern\Investments\Enums\PayoutStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\InstrumentPrice;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Kern\Legislation\Database\Factories\SourceDocumentFactory;
use Kern\Legislation\DataTransferObjects\PortalActToRead;
use Kern\Legislation\Enums\SourceDocumentVersionStatus;
use Kern\Legislation\Events\LawDeletionChanged;
use Kern\Legislation\Events\PortalActRead;
use Kern\Legislation\Jobs\DeleteLawJob;
use Kern\Legislation\Jobs\ReadPortalAct;
use Kern\Legislation\Legislatie\Exceptions\PortalAnswerException;
use Kern\Legislation\Models\IgnoredAct;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Models\SectionHistory;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Repositories\OfficialSourceRepository;
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Legislation\Services\LegislationTemporalQuery;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\Notices\IssueOnOfferNotice;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\HeldAt;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use RuntimeException;
use Tests\TestCase;

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
    // Deleting a law asks again for the password or code (`verify-recent`); these tests are past that.
    verifySession();
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

function soapAct(string $type, string $number, string $title, string $link, string $inForce = '2016-01-01'): string
{
    return "<a:Legi><a:DataVigoare>{$inForce}</a:DataVigoare><a:Emitent>Parlamentul</a:Emitent>"
        . "<a:LinkHtml>{$link}</a:LinkHtml><a:Numar>{$number}</a:Numar><a:Publicatie>Monitorul Oficial</a:Publicatie>"
        . "<a:Text>…</a:Text><a:TipAct>{$type}</a:TipAct><a:Titlu>{$title}</a:Titlu></a:Legi>";
}

function soapEnvelope(string $inner): string
{
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $inner . '</s:Body></s:Envelope>';
}

function consolidationPage(string $salaries, bool $withArticle5, string $note = '', string $articleNote = ''): string
{
    $article5 = $withArticle5
        ? '<span class="S_ART"><span class="S_ART_TTL">Articolul 5</span><span class="S_ART_BDY"><span class="S_PAR">Dispoziție tranzitorie.</span></span></span>'
        : '';

    return <<<HTML
        <html><body><div class="content_forma_act">
        <span class="S_TTL"><span class="S_TTL_TTL">Titlul V</span><span class="S_TTL_DEN">Contribuții sociale obligatorii</span><span class="S_TTL_BDY">
          <span class="S_CAP"><span class="S_CAP_TTL">Capitolul III</span><span class="S_CAP_DEN">Contribuția de asigurări sociale de sănătate</span><span class="S_CAP_BDY">
            <span class="S_ART"><span class="S_ART_TTL">Articolul 170</span><span class="S_ART_BDY">{$articleNote}
              <span class="S_ALN"><span class="S_ALN_TTL">(1)</span><span class="S_ALN_BDY"><span class="S_PAR">Baza de calcul este de {$salaries} salarii minime brute pe țară.</span>{$note}</span></span>
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
 * @param  string  $noted  a note the 2021 page carries on art. 170 alin. (1), as the portal writes one
 * @param  string  $articleNoted  one it carries on art. 170 itself
 */
function portal(array $failing = [], string $noted = '', string $articleNoted = ''): void
{
    $afis = '<div id="istoric_fa">'
        . "<a title='Consolidarea din 01.08.2025' href='~/../../../Public/DetaliiDocument/1003'>01.08.2025</a><br/>"
        . "<a title='Consolidarea din 01.01.2021' href='~/../../../Public/DetaliiDocument/1002'>01.01.2021</a><br/>"
        . "<a title='Consolidarea din 01.01.2016' href='~/../../../Public/DetaliiDocument/1001'>01.01.2016</a>"
        . '</div>';

    $pages = [
        '1001' => consolidationPage('6', true),
        '1002' => consolidationPage('12', true, $noted, $articleNoted),
        '1003' => consolidationPage('12', false),
    ];

    Http::fake(static function (HttpRequest $request) use ($afis, $pages, $failing) {
        $url = $request->url();

        if (str_contains($url, '/apiws/')) {
            $action = $request->header('SOAPAction')[0] ?? '';

            if (str_contains($action, 'GetToken')) {
                return Http::response(soapEnvelope('<GetTokenResponse xmlns="http://tempuri.org/"><GetTokenResult>token-1</GetTokenResult></GetTokenResponse>'));
            }

            // Page 1 holds both acts numbered 227 that year; any later page is empty.
            $acts = str_contains($request->body(), '<fws:NumarPagina>1</fws:NumarPagina>')
                ? soapAct('DECRET', '227', 'DECRET nr. 227 din 23 ianuarie 2015 pentru numirea unui procuror EMITENT: PREŞEDINTELE ROMÂNIEI PUBLICAT ÎN: MONITORUL OFICIAL nr. 63 din 26 ianuarie 2015', 'http://legislatie.just.ro/Public/DetaliiDocument/164954')
                    . soapAct('LEGE', '227', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal EMITENT: PARLAMENTUL PUBLICAT ÎN: MONITORUL OFICIAL nr. 688 din 10 septembrie 2015', 'http://legislatie.just.ro/Public/DetaliiDocument/171280')
                : '';

            return Http::response(soapEnvelope('<SearchResponse xmlns="http://tempuri.org/"><SearchResult xmlns:a="http://schemas.datacontract.org/2004/07/FreeWebService">' . $acts . '</SearchResult></SearchResponse>'));
        }

        if (str_contains($url, '/Public/DetaliiDocument/171280')) {
            // The act's own reference in the form's box — and, before it, a citation of another act,
            // which is never followed.
            return Http::response('<html><body><div id="div_Formaconsolidata" class="content_forma_act">'
                . '<span class="S_PAR">În temeiul <A href="~/../../../Public/DetaliiDocumentAfis/47355">art. 108 din Constituția României</A></span>'
                . '<span class="S_PAR"><span class="S_REF"><A href="~/../../../Public/DetaliiDocumentAfis/171282">COD FISCAL 08/09/2015</A></span></span>'
                . '</div></body></html>');
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
        ->and($outline->json('headings.*.last_citation'))->toBe(['Art. 5', 'Art. 170']);

    // One part at a time: the title holds both articles, the chapter only its own; a provision opens
    // the part its article stands in, marked.
    $title = $this->actingAs($user)->getJson("/api/v1/legislation/laws/{$law->id}/text?as_of=2019-05-01&heading=" . rawurlencode('Titlul V'))->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $chapter = $this->actingAs($user)->getJson("/api/v1/legislation/laws/{$law->id}/text?as_of=2019-05-01&heading=" . rawurlencode('Titlul V Capitolul III'))->assertOk();
    // The wording is read for the part shown, never the whole law: art. 5 stands outside the chapter.
    $wordingRead = array_merge(...array_column(array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => str_contains($query['query'], '"text"') && str_contains($query['query'], 'ext_source_sections'),
    ), 'bindings'));
    DB::disableQueryLog();
    $provision = $this->actingAs($user)->getJson("/api/v1/legislation/laws/{$law->id}/text?as_of=2019-05-01&provision=" . rawurlencode('Art. 170 alin. (1)'))->assertOk();

    expect($title->json('sections.*.label'))->toContain('Articolul 170', 'Articolul 5')
        ->and($chapter->json('sections.*.label'))->toContain('Articolul 170')->not->toContain('Articolul 5')
        ->and($wordingRead)->toEqualCanonicalizing($chapter->json('sections.*.id'))
        ->and($chapter->json('heading.*.path'))->toBe(['Titlul V', 'Titlul V Capitolul III'])
        ->and($provision->json('heading.*.path'))->toBe(['Titlul V', 'Titlul V Capitolul III'])
        ->and($provision->json('focus'))->toBe($provision->json('sections.1.id'))
        ->and($text->json('heading.*.path'))->toBe(['Titlul V']);

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

it('drops a version an interrupted read left behind', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();

    // What a job killed between downloading a page and marking it read leaves: a version with no
    // consolidation, no dates, and its own sections.
    $orphan = SourceDocumentVersion::factory()->create(['source_document_id' => $law->id, 'consolidation_ref' => null]);
    \Kern\Legislation\Models\SourceSection::factory()->create(['document_version_id' => $orphan->id]);

    \Kern\Legislation\Jobs\FinishLegislationSync::dispatchSync($law->id);

    expect(SourceDocumentVersion::query()->whereKey($orphan->id)->exists())->toBeFalse()
        ->and(SourceDocumentVersion::query()->where('source_document_id', $law->id)->count())->toBe(3);
});

/**
 * A government decision never amended, as the portal serves the one setting the 2021 minimum wage:
 * its page is its text, citing other acts, with no consolidated form — and its number shared that
 * year with a council's decision.
 */
function governmentDecisionPortal(): void
{
    Http::fake(static function (HttpRequest $request) {
        $url = $request->url();

        if (str_contains($url, '/apiws/')) {
            if (str_contains($request->header('SOAPAction')[0] ?? '', 'GetToken')) {
                return Http::response(soapEnvelope('<GetTokenResponse xmlns="http://tempuri.org/"><GetTokenResult>token-1</GetTokenResult></GetTokenResponse>'));
            }

            $acts = str_contains($request->body(), '<fws:NumarPagina>1</fws:NumarPagina>')
                ? soapAct('HOTĂRÂRE', '4', 'HOTĂRÂRE nr. 4 din 7 ianuarie 2021 privind aprobarea bugetului EMITENT: CONSILIUL LOCAL AL MUNICIPIULUI TEST PUBLICAT ÎN: MONITORUL OFICIAL nr. 30 din 11 ianuarie 2021', 'http://legislatie.just.ro/Public/DetaliiDocument/236000', '2021-01-11')
                    . soapAct('HOTĂRÂRE', '4', 'HOTĂRÂRE nr. 4 din 13 ianuarie 2021 pentru stabilirea salariului de bază minim brut pe ţară garantat în plată EMITENT: GUVERNUL PUBLICAT ÎN: MONITORUL OFICIAL nr. 40 din 13 ianuarie 2021', 'http://legislatie.just.ro/Public/DetaliiDocument/236118', '2021-01-13')
                : '';

            return Http::response(soapEnvelope('<SearchResponse xmlns="http://tempuri.org/"><SearchResult xmlns:a="http://schemas.datacontract.org/2004/07/FreeWebService">' . $acts . '</SearchResult></SearchResponse>'));
        }

        if (str_contains($url, '/Public/DetaliiDocument/236118')) {
            return Http::response('<html><body><div id="div_Formaconsolidata" class="content_forma_act">'
                . '<span class="S_PAR">În temeiul <A href="~/../../../Public/DetaliiDocumentAfis/47355">art. 108 din Constituția României</A>,</span>'
                . '<span class="S_ART"><span class="S_ART_TTL">Articolul 1</span><span class="S_ART_BDY"><span class="S_PAR">Începând cu data de 14 ianuarie 2021, salariul de bază minim brut pe țară garantat în plată se stabilește la suma de 2.300 lei lunar.</span></span></span>'
                . '</div></body></html>');
        }

        return Http::response('', 404);
    });
}

it('reads an act never amended as its one version, never following the acts it cites', function (): void {
    governmentDecisionPortal();

    $started = app(SyncLegislationDocument::class)('HOTARARE', 4, 2021, portalId: '236118');
    $decision = SourceDocument::query()->where('external_identifier', 'HOTARARE 4/2021')->sole();
    $version = SourceDocumentVersion::query()->where('source_document_id', $decision->id)->sole();

    expect([$started->listed, $started->toRead])->toBe([1, 1])
        ->and($decision->issuer)->toBe('GUVERNUL')
        ->and($decision->canonical_url)->toEndWith('/Public/DetaliiDocument/236118')
        ->and([$version->consolidation_ref, $version->effective_from?->format('Y-m-d'), $version->effective_until])->toBe(['236118', '2021-01-13', null])
        ->and($version->sections()->where('citation_label', 'Art. 1')->sole()->text)->toContain('2.300 lei lunar');
});

it('tells apart acts sharing a number by the portal id, and refuses without one', function (): void {
    governmentDecisionPortal();

    expect(fn () => app(SyncLegislationDocument::class)('HOTARARE', 4, 2021))->toThrow(PortalAnswerException::class)
        ->and(SourceDocument::query()->count())->toBe(0);
});

it('finds acts on the portal by title or by number, and says which are read already', function (): void {
    governmentDecisionPortal();

    Artisan::call('kern:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    app(SyncLegislationDocument::class)('HOTARARE', 4, 2021, portalId: '236118');

    /** @var list<array<string, mixed>> $byNumber */
    $byNumber = portalSearch($this, $admin, ['number' => 4, 'year' => 2021])->assertJsonPath('status', 'done')->json('acts');

    expect(array_column($byNumber, 'portal_id'))->toBe(['236000', '236118'])
        ->and(array_column($byNumber, 'importable'))->toBe([true, true])
        ->and($byNumber[0]['law_id'])->toBeNull()
        ->and($byNumber[1]['law_id'])->toBe(SourceDocument::query()->where('portal_document_id', '236118')->sole()->id);

    // A year given to a title search is the act's own, compared here: 2020 has none of them.
    portalSearch($this, $admin, ['title' => 'salariului minim', 'year' => 2020])->assertJsonCount(0, 'acts');

    // An act older than the laws read here is found, and said not to be importable, and why.
    config(['legislation.form.min_year' => 2022]);
    /** @var list<array{importable: bool, before_min_year: bool}> $old */
    $old = portalSearch($this, $admin, ['title' => 'salariului minim'])->json('acts');
    expect($old)->not->toBe([])
        ->and(array_filter($old, static fn (array $act): bool => $act['importable'] || ! $act['before_min_year']))->toBe([]);
    $this->actingAs($admin)->postJson('/api/v1/legislation/portal-searches', ['title' => 'ab'])->assertStatus(422);
    $this->actingAs($admin)->postJson('/api/v1/legislation/portal-searches', ['number' => 4])->assertStatus(422);
    $this->actingAs($admin)->postJson('/api/v1/legislation/portal-searches', ['title' => 'salariului', 'number' => 4, 'year' => 2021])->assertStatus(422);
});

// What the portal said on 2026-11-07, 2026-11-09 and 2026-11-10, when its own search server refused it: a
// fault, not an answer — asked again before it is believed, and then a search that failed, saying the
// portal's search is down rather than that the portal did not answer.
it('asks the service again when it fails, and ends a search it never answers as failed, saying why', function (): void {
    Sleep::fake();
    Http::fake(['legislatie.just.ro/*' => static fn (HttpRequest $request) => str_contains($request->header('SOAPAction')[0] ?? '', 'GetToken')
        ? Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><GetTokenResponse xmlns="http://tempuri.org/"><GetTokenResult>T</GetTokenResult></GetTokenResponse></s:Body></s:Envelope>')
        : Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultcode>a:InternalServiceFault</faultcode></s:Fault></s:Body></s:Envelope>', 500)]);
    Artisan::call('kern:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    portalSearch($this, $admin, ['title' => 'codul fiscal'])
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('failure', 'portal_search_down');

    Sleep::assertSleptTimes(config()->integer('sources.entries.LEGISLATIE.search_refused_retries'));
});

it('searches the portal only for those who may read a law in', function (): void {
    Artisan::call('kern:access:sync');

    $this->actingAs(User::factory()->create())->postJson('/api/v1/legislation/portal-searches', ['title' => 'codul fiscal'])->assertForbidden();
});

it('imports the act chosen on the screen, through the task, to the end', function (): void {
    governmentDecisionPortal();
    Artisan::call('kern:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    // Exactly what the dialog sends: numbers as numbers. They reach the command as text, as they
    // would from a terminal — an integer here once failed the command's own check.
    $this->actingAs($admin)
        ->postJson('/api/v1/operations/tasks/legislation.ingest/run', ['act_type' => 'HOTARARE', 'act_number' => 4, 'act_year' => 2021, 'portal_id' => 236118])
        ->assertStatus(202);

    expect(SourceDocument::query()->where('portal_document_id', '236118')->sole()->external_identifier)->toBe('HOTARARE 4/2021');
});

it('imports the acts chosen together, each read on the watches\' queue, one already read passed over', function (): void {
    governmentDecisionPortal();
    Artisan::call('kern:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    app(SyncLegislationDocument::class)('HOTARARE', 4, 2021, portalId: '236118');
    $before = DB::table('audit_events')->where('event_type', 'LegislationImportRequested')->count();

    Queue::fake();
    $this->actingAs($admin)->postJson('/api/v1/legislation/imports', ['acts' => [
        ['type' => 'HOTARARE', 'number' => 4, 'year' => 2021, 'portal_id' => 236000],
        ['type' => 'HOTARARE', 'number' => 4, 'year' => 2021, 'portal_id' => 236118],
    ]])
        ->assertStatus(202)
        ->assertJsonPath('queued', 1)
        ->assertJsonPath('already_read', 1);

    Queue::assertPushedOn('legislation-watches', ReadPortalAct::class, static fn (ReadPortalAct $job): bool => $job->act->portalId === '236000' && $job->userId === $admin->id);
    Queue::assertPushed(ReadPortalAct::class, 1);
    expect(DB::table('audit_events')->where('event_type', 'LegislationImportRequested')->count())->toBe($before + 1);
});

it('reads an act an import queued, as the ingest task would, and tells whoever chose it how it went', function (): void {
    governmentDecisionPortal();

    Event::fake([PortalActRead::class]);

    (new ReadPortalAct(new PortalActToRead('HOTARARE', 4, 2021, '236118'), 'user-1'))->handle(app(SyncLegislationDocument::class));

    expect(SourceDocument::query()->where('portal_document_id', '236118')->sole()->external_identifier)->toBe('HOTARARE 4/2021');
    Event::assertDispatched(PortalActRead::class, static fn (PortalActRead $event): bool => $event->portalId === '236118' && $event->userId === 'user-1' && $event->read);

    (new ReadPortalAct(new PortalActToRead('HOTARARE', 4, 2021, '236000'), 'user-1'))->failed(new RuntimeException('the portal did not answer'));
    Event::assertDispatched(PortalActRead::class, static fn (PortalActRead $event): bool => $event->portalId === '236000' && ! $event->read);
});

it('refuses an import too large, with an act twice, of a type not read, or by someone who may not read laws in', function (): void {
    Artisan::call('kern:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $act = ['type' => 'HOTARARE', 'number' => 4, 'year' => 2021, 'portal_id' => 236000];
    Queue::fake();

    $this->actingAs($admin)->postJson('/api/v1/legislation/imports', ['acts' => [$act, $act]])->assertUnprocessable();
    $this->actingAs($admin)->postJson('/api/v1/legislation/imports', ['acts' => [[...$act, 'type' => 'NORMA']]])->assertUnprocessable();
    $this->actingAs($admin)->postJson('/api/v1/legislation/imports', ['acts' => []])->assertUnprocessable();
    config(['legislation.imports.max_acts' => '1']);
    $this->actingAs($admin)->postJson('/api/v1/legislation/imports', ['acts' => [$act, [...$act, 'portal_id' => 236118]]])->assertUnprocessable();
    $this->actingAs(User::factory()->create())->postJson('/api/v1/legislation/imports', ['acts' => [$act]])->assertForbidden();

    Queue::assertNothingPushed();
});

it('filters a portal search to one type', function (): void {
    governmentDecisionPortal();
    Artisan::call('kern:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    portalSearch($this, $admin, ['number' => 4, 'year' => 2021, 'type' => 'LEGE'])->assertJsonCount(0, 'acts');
    portalSearch($this, $admin, ['number' => 4, 'year' => 2021, 'type' => 'HOTARARE'])->assertJsonCount(2, 'acts');
    $this->actingAs($admin)->postJson('/api/v1/legislation/portal-searches', ['number' => 4, 'year' => 2021, 'type' => 'DECRET'])->assertStatus(422);
});

it('adopts a consolidation already read that the list names by another id, and reads it not again', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $before = SourceDocumentVersion::query()->where('source_document_id', $law->id)->orderBy('effective_from')->pluck('id')->all();

    // As an earlier sync left them, under ids the portal's list no longer uses for those days.
    SourceDocumentVersion::query()->where('source_document_id', $law->id)->update(['consolidation_ref' => DB::raw("'old-' || consolidation_ref")]);
    $law->forceFill(['consolidations' => array_map(static fn (array $entry): array => [...$entry, 'ref' => 'old-' . $entry['ref']], $law->consolidations ?? [])])->save();

    $started = app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $versions = SourceDocumentVersion::query()->where('source_document_id', $law->id)->orderBy('effective_from')->get();

    expect([$started->listed, $started->toRead])->toBe([3, 0])
        ->and($versions->pluck('id')->all())->toBe($before)
        ->and($versions->pluck('consolidation_ref')->all())->toBe(['1001', '1002', '1003'])
        ->and(array_column(fiscalCode()->consolidations ?? [], 'ref'))->toBe(['1001', '1002', '1003']);
});

it('dates a wording from the portal\'s note where no consolidation between tells when it began', function (): void {
    // As the portal prints it inside the provision; the list has no consolidation from 2016 to 2021.
    portal([], '<span class="S_PAR">(la 01-01-2018, Alineatul (1) din Articolul 170 a fost modificat de Punctul 1, Articolul I din ORDONANȚA DE URGENȚĂ nr. 79 din 8 noiembrie 2017, publicată în MONITORUL OFICIAL nr. 885 din 10 noiembrie 2017)</span>');

    app(SyncLegislationDocument::class)('LEGE', 227, 2015);

    $stretches = SectionHistory::query()
        ->where('source_document_id', fiscalCode()->id)
        ->where('citation_label', 'Art. 170 alin. (1)')
        ->orderBy('version_index')
        ->get()
        ->map(static fn (SectionHistory $h): array => [$h->valid_from->format('Y-m-d'), $h->valid_until?->format('Y-m-d'), $h->dated_by_note])
        ->all();

    expect($stretches)->toBe([
        ['2016-01-01', '2017-12-31', false],
        ['2018-01-01', null, true],
    ]);
});

it('dates a wording by its article\'s note when the article was replaced whole', function (): void {
    // The note on the article, as the portal prints it after the article's heading; none on the paragraph.
    portal([], '', '<span class="S_PAR">(la 01-01-2018, Articolul 170 din Capitolul III a fost modificat de Punctul 20, Articolul I din ORDONANȚA DE URGENȚĂ nr. 79 din 8 noiembrie 2017)</span>');

    app(SyncLegislationDocument::class)('LEGE', 227, 2015);

    $stretches = SectionHistory::query()
        ->where('source_document_id', fiscalCode()->id)
        ->where('citation_label', 'Art. 170 alin. (1)')
        ->orderBy('version_index')
        ->get()
        ->map(static fn (SectionHistory $h): array => [$h->valid_from->format('Y-m-d'), $h->dated_by_note])
        ->all();

    expect($stretches)->toBe([['2016-01-01', false], ['2018-01-01', true]]);
});

it('deletes a law read in by mistake, with every consolidation and provision it brought', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $versions = SourceDocumentVersion::query()->where('source_document_id', $law->id)->pluck('id');

    expect($versions)->toHaveCount(3)
        ->and(SourceSection::query()->whereIn('document_version_id', $versions)->count())->toBeGreaterThan(0)
        ->and(SectionHistory::query()->where('source_document_id', $law->id)->count())->toBeGreaterThan(0);

    // The removal runs on the queue — `sync` in tests, so it runs within the request — and the request
    // only answers that it started (ADR: a law of hundreds of thousands of provisions is too slow to
    // delete while a request waits).
    $this->actingAs(settingsAdminUser())->deleteJson("/api/v1/legislation/laws/{$law->id}")
        ->assertStatus(202)
        ->assertJsonPath('status', 'deleting');

    // The law, its consolidations, its provisions and what was assembled from them: all of it.
    expect(SourceDocument::query()->whereKey($law->id)->exists())->toBeFalse()
        ->and(SourceDocumentVersion::query()->whereIn('id', $versions)->count())->toBe(0)
        ->and(SourceSection::query()->whereIn('document_version_id', $versions)->count())->toBe(0)
        ->and(SectionHistory::query()->where('source_document_id', $law->id)->count())->toBe(0)
        ->and(DB::table('audit_events')->where('event_type', 'LawDeleted')->count())->toBe(1);
});

it('answers at once and leaves the deleting to a job, so a large law never holds the request open', function (): void {
    Queue::fake();
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $admin = settingsAdminUser();

    $this->actingAs($admin)->deleteJson("/api/v1/legislation/laws/{$law->id}", ['ignore' => true])
        ->assertStatus(202)
        ->assertJsonPath('status', 'deleting')
        ->assertJsonPath('id', $law->id);

    // The law is still there: the request only queued the removal, for whoever asked, with their choice.
    expect(SourceDocument::query()->whereKey($law->id)->exists())->toBeTrue();
    Queue::assertPushed(DeleteLawJob::class, static fn (DeleteLawJob $job): bool => $job->lawId === $law->id
        && $job->ignore === true
        && $job->actorId === $admin->id
        && $job->userId === $admin->id);
});

it('says over the socket when a removal is done, for whoever asked', function (): void {
    Event::fake([LawDeletionChanged::class]);
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $admin = settingsAdminUser();

    // The queue is `sync` in tests, so the job runs within the request and deletes the law now.
    $this->actingAs($admin)->deleteJson("/api/v1/legislation/laws/{$law->id}")->assertStatus(202);

    expect(SourceDocument::query()->whereKey($law->id)->exists())->toBeFalse();
    Event::assertDispatched(LawDeletionChanged::class, static fn (LawDeletionChanged $event): bool => $event->lawId === $law->id
        && $event->userId === $admin->id
        && $event->status === 'deleted');
});

it('removes the pages a deleted law was read from, but not one another law was read from too', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    /** @var list<string> $paths */
    $paths = SourceDocumentVersion::query()->where('source_document_id', $law->id)->pluck('raw_object_path')->unique()->values()->all();
    // The same bytes read by another law are stored once, under the same path, for both.
    expect(count($paths))->toBeGreaterThan(1);
    $shared = $paths[0];
    SourceDocumentVersion::factory()->create(['raw_object_path' => $shared]);

    $this->actingAs(settingsAdminUser())->deleteJson("/api/v1/legislation/laws/{$law->id}")
        ->assertStatus(202);

    expect(Storage::disk('s3')->exists($shared))->toBeTrue();
    foreach (array_slice($paths, 1) as $path) {
        expect(Storage::disk('s3')->exists($path))->toBeFalse();
    }
});

it('refuses to delete a law a fiscal value rests on, and says how many', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $section = SourceSection::query()
        ->whereIn('document_version_id', SourceDocumentVersion::query()->where('source_document_id', $law->id)->select('id'))
        ->firstOrFail();
    $parameter = FiscalParameter::factory()->create(['code' => 'RO.CASS.RATE', 'value_type' => 'DECIMAL']);
    insertApprovedFiscalParameterVersion($parameter->id, $section->id);

    $this->actingAs(settingsAdminUser())->deleteJson("/api/v1/legislation/laws/{$law->id}")
        ->assertStatus(409)
        ->assertJsonPath('reason', 'LAW_HAS_VALUES')
        ->assertJsonPath('values', 1);

    // Nothing was touched: a law the values rest on stays whole.
    expect(SourceDocument::query()->whereKey($law->id)->exists())->toBeTrue()
        ->and(SourceSection::query()->whereKey($section->id)->exists())->toBeTrue();
});

/** A made-up bond read from this law, with a coupon, a price, and its "on offer" notice in one person's bell. */
function bondReadFrom(SourceDocument $law): FinancialInstrument
{
    $bond = FinancialInstrument::query()->create([
        'kind' => InstrumentKind::GOVERNMENT_BOND,
        'programme' => Programme::TEZAUR,
        'issue_code' => '0001',
        'name' => 'Example issue',
        'issuer_name' => 'Example issuer',
        'currency' => 'RON',
        'status' => InstrumentStatus::ON_OFFER,
        'terms_document_id' => $law->id,
    ]);
    InstrumentPayout::query()->create([
        'instrument_id' => $bond->id,
        'kind' => PayoutKind::COUPON,
        'payment_date' => '2027-06-01',
        'amount_per_unit' => '5',
        'status' => PayoutStatus::ANNOUNCED,
        'source' => PayoutSource::ORDER,
    ]);
    InstrumentPrice::query()->create([
        'instrument_id' => $bond->id,
        'trading_date' => '2026-11-09',
        'price' => '100',
        'priced_at' => now(),
        'evidence_id' => SourceDocumentVersion::factory()->create()->id,
    ]);
    Notification::send(User::factory()->create(), IssueOnOfferNotice::about($bond->id));

    return $bond;
}

it('deletes an order nobody holds a bond of, with its bonds, their payouts, prices and notices', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $bond = bondReadFrom($law);
    $other = User::factory()->create();
    Notification::send($other, IssueOnOfferNotice::about('01a1bd7e-ea89-701b-afd6-9c6ad267cd04'));

    $this->actingAs(settingsAdminUser())->deleteJson("/api/v1/legislation/laws/{$law->id}")->assertStatus(202);

    expect(SourceDocument::query()->whereKey($law->id)->exists())->toBeFalse()
        ->and(FinancialInstrument::query()->whereKey($bond->id)->exists())->toBeFalse()
        ->and(InstrumentPayout::query()->where('instrument_id', $bond->id)->exists())->toBeFalse()
        ->and(InstrumentPrice::query()->where('instrument_id', $bond->id)->exists())->toBeFalse()
        // Its notice goes; one about another issue stays.
        ->and(DB::table('notifications')->where('type', IssueOnOfferNotice::key())->pluck('notifiable_id')->all())->toBe([$other->id]);
});

it('refuses to delete an order while someone holds one of its bonds, and touches nothing', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $bond = bondReadFrom($law);
    $subject = TaxSubject::factory()->create();
    InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->create([
        'tax_subject_id' => $subject->id,
        'financial_account_id' => FinancialAccount::create([
            'tax_subject_id' => $subject->id,
            'kind' => AccountKind::INVESTMENTS,
            'display_name_ciphertext' => 'Example account',
            'source' => RecordSource::MANUAL,
        ])->id,
        'instrument_id' => $bond->id,
        'units' => '10',
        'price_per_unit' => '100',
        'bought_on' => '2026-01-10',
        'fees_minor' => 0,
        'currency' => 'RON',
        'held_at' => HeldAt::BANK,
    ]);

    $this->actingAs(settingsAdminUser())->deleteJson("/api/v1/legislation/laws/{$law->id}")
        ->assertStatus(409)
        ->assertJsonPath('reason', 'LAW_IS_READ_FROM')
        ->assertJsonPath('read_from.holdings', 1);

    expect(SourceDocument::query()->whereKey($law->id)->exists())->toBeTrue()
        ->and(FinancialInstrument::query()->whereKey($bond->id)->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('type', IssueOnOfferNotice::key())->count())->toBe(1);
});

it('ignores a deleted act in the watches from then on when asked, only one a watch read in', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $watch = LawWatch::query()->create([
        'search_words' => 'exemplul', 'act_type' => 'LEGE', 'first_year' => 2015, 'every_hours' => 24, 'enabled' => true, 'next_run_at' => now(),
    ]);

    // Not a watch's find: `ignore` has nothing to act on, so no act is ignored. (The queue is `sync`
    // in tests, so the removal and its ignoring, or not, happen within the request.)
    $this->actingAs(settingsAdminUser())->deleteJson("/api/v1/legislation/laws/{$law->id}", ['ignore' => true])
        ->assertStatus(202);

    expect(IgnoredAct::query()->where('portal_document_id', $law->portal_document_id)->exists())->toBeFalse();

    app(SyncLegislationDocument::class)('LEGE', 227, 2015, null, null, $watch->id);
    $law = fiscalCode();

    // A watch read this one in: `ignore` passes it over in that watch's searches from then on.
    $this->actingAs(settingsAdminUser())->deleteJson("/api/v1/legislation/laws/{$law->id}", ['ignore' => true])
        ->assertStatus(202);

    expect(IgnoredAct::query()->where('portal_document_id', $law->portal_document_id)->value('title'))->toBe($law->title);
});

it('lists the laws of one type, year or watch, and what may be filtered by — the watches only to those who see them', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $watch = LawWatch::query()->create([
        'search_words' => 'exemplul', 'act_type' => 'LEGE', 'first_year' => 2015, 'every_hours' => 24, 'enabled' => true, 'next_run_at' => now(),
    ]);
    $law->update(['found_by_watch_id' => $watch->id]);
    $operator = settingsAdminUser();

    $this->actingAs($operator)->getJson('/api/v1/legislation/laws?type=LEGE&year=' . $law->act_date?->format('Y') . "&watch={$watch->id}")
        ->assertOk()
        ->assertJsonCount(1, 'laws')
        ->assertJsonPath('laws.0.watch_id', $watch->id)
        ->assertJsonPath('filters.types.0.value', 'LEGE')
        ->assertJsonPath('filters.watches', [['id' => $watch->id, 'words' => 'exemplul']]);
    $this->actingAs($operator)->getJson('/api/v1/legislation/laws?type=ORDIN')->assertJsonCount(0, 'laws');

    $reader = User::factory()->create();
    $this->actingAs($reader)->getJson('/api/v1/legislation/laws')->assertOk()->assertJsonPath('filters.watches', []);
    $this->actingAs($reader)->getJson("/api/v1/legislation/laws?watch={$watch->id}")->assertUnprocessable();
});

it('lists the laws a page at a time, oldest act first, saying how many there are in all', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();

    foreach (range(1, 11) as $number) {
        SourceDocumentFactory::new()->create([
            'official_source_id' => $law->official_source_id,
            'document_type' => 'LEGE',
            'external_identifier' => "LEGE {$number}/2020",
            'act_number' => (string) $number,
            'act_date' => sprintf('2020-01-%02d', $number),
        ]);
    }

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/legislation/laws?per_page=10')
        ->assertOk()
        ->assertJsonCount(10, 'laws')
        ->assertJsonPath('laws.0.id', $law->id)
        ->assertJsonPath('total', 12)
        ->assertJsonPath('page', 1)
        ->assertJsonPath('per_page', 10);
    $this->actingAs($user)->getJson('/api/v1/legislation/laws?per_page=10&page=2')
        ->assertOk()
        ->assertJsonCount(2, 'laws')
        ->assertJsonPath('total', 12);
    $this->actingAs($user)->getJson('/api/v1/legislation/laws?per_page=7')->assertUnprocessable();
    $this->actingAs($user)->getJson('/api/v1/legislation/laws?page=0')->assertUnprocessable();
});

it('does not delete an exchange-rate source through the law route, and refuses a person who may not run operations', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();

    // The same table holds the BNR's rates; they are not laws and are not found here.
    $rates = SourceDocument::query()->create([
        'official_source_id' => app(OfficialSourceRepository::class)->idForCode('BNR'),
        'document_type' => 'FX_DAILY',
        'canonical_url' => 'https://www.bnr.ro/nbrfxrates.xml',
    ]);

    $this->actingAs(settingsAdminUser())->deleteJson("/api/v1/legislation/laws/{$rates->id}")->assertNotFound();
    $this->actingAs(ordinaryUser())->deleteJson("/api/v1/legislation/laws/{$law->id}")->assertForbidden();

    expect(SourceDocument::query()->whereKey($rates->id)->exists())->toBeTrue()
        ->and(SourceDocument::query()->whereKey($law->id)->exists())->toBeTrue();
});

/**
 * Starts a portal search as the dialog does, reads it to the end as the queue would, and answers it as the
 * dialog reads it back.
 *
 * @param  array<string, int|string>  $query
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function portalSearch(TestCase $test, User $user, array $query): TestResponse
{
    Queue::fake();
    $id = portalSearchId($test->actingAs($user)->postJson('/api/v1/legislation/portal-searches', $query));
    readPortalSearchPages();

    return $test->actingAs($user)->getJson("/api/v1/legislation/portal-searches/{$id}")->assertOk();
}

it('keeps a law’s whole title, however long the Official Gazette prints it', function (): void {
    // A Ministry of Finance order approving an issue's final terms is titled in some 400 characters.
    $title = 'ORDIN nr. 1 din 1 ianuarie 2026 privind aprobarea Termenilor finali ' . str_repeat('ai emisiunilor de titluri de stat ', 12);

    $law = SourceDocument::query()->create([
        'official_source_id' => app(OfficialSourceRepository::class)->idForCode('LEGISLATIE'),
        'document_type' => 'ORDIN',
        'canonical_url' => 'https://legislatie.just.ro/Public/DetaliiDocument/1',
        'title' => $title,
    ]);

    expect(mb_strlen($title))->toBeGreaterThan(255)
        ->and(SourceDocument::query()->findOrFail($law->id)->title)->toBe($title);
});

it('asks again for the password or code before a law is deleted', function (): void {
    $operator = withAuthenticator(settingsAdminUser());
    forgetVerification();

    $this->actingAs($operator)->deleteJson('/api/v1/legislation/laws/' . \Illuminate\Support\Str::uuid7())->assertForbidden()
        ->assertJsonPath('reason', fn (string $reason): bool => in_array($reason, ['VERIFICATION_REQUIRED', 'PASSWORD_CONFIRMATION_REQUIRED'], true));
});

it('stops reading a law when storage fails, marks no consolidation, and says why', function (): void {
    portal();
    Queue::fake();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();

    // Storage down: the fault is this server's, not any page's.
    $down = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $down->shouldReceive('exists')->andThrow(\League\Flysystem\UnableToCheckFileExistence::forLocation('sources/example'));
    Storage::set('s3', $down);
    app()->call([new \Kern\Legislation\Jobs\ReadNextConsolidation($law->id), 'handle']);
    $law->refresh();

    expect($law->reading_stopped?->value)->toBe('storage_unavailable')
        ->and($law->reading_stopped_at)->not->toBeNull()
        ->and(collect($law->consolidations)->where('failed', true)->all())->toBe([])
        ->and(SourceDocumentVersion::query()->where('source_document_id', $law->id)->count())->toBe(0);
    // Stopped: the next consolidation is not queued.
    Queue::assertPushed(\Kern\Legislation\Jobs\ReadNextConsolidation::class, 1);

    $this->actingAs(settingsAdminUser())->getJson("/api/v1/legislation/laws/{$law->id}")->assertOk()
        ->assertJsonPath('law.reading_stopped', 'storage_unavailable')
        ->assertJsonPath('law.portal_id', '171280')
        ->assertJsonPath('law.year', 2015);
});

it('clears a stopped reading when the law is read again', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();
    $law->forceFill(['reading_stopped' => 'storage_unavailable', 'reading_stopped_at' => now()])->save();

    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law->refresh();

    expect($law->reading_stopped)->toBeNull()
        ->and($law->reading_stopped_at)->toBeNull()
        ->and(SourceDocumentVersion::query()->where('source_document_id', $law->id)->count())->toBe(3);
});
