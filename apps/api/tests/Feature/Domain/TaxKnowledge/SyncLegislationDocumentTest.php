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
 * @param  (callable(): bool)|null  $actListsItsOwn  whether the law's own page lists its consolidations — asked on each request
 */
function portal(array $failing = [], ?callable $actListsItsOwn = null): void
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
        // The code in 2018: not in the code's own list.
        '1005' => consolidationPage('8', true),
    ];

    // The law's own list, as LEGE 227/2015 has it: one more day, and each entry's page only a
    // reference to the code as it stood that day.
    $ownList = '<div id="istoric_fa">'
        . "<a title='Consolidarea din 01.08.2025' href='~/../../../Public/DetaliiDocument/2004'>01.08.2025</a><br/>"
        . "<a title='Consolidarea din 01.01.2021' href='~/../../../Public/DetaliiDocument/2003'>01.01.2021</a><br/>"
        . "<a title='Consolidarea din 01.01.2018' href='~/../../../Public/DetaliiDocument/2002'>01.01.2018</a><br/>"
        . "<a title='Consolidarea din 01.01.2016' href='~/../../../Public/DetaliiDocument/2001'>01.01.2016</a>"
        . '</div>';
    $reference = static fn (string $to): string => '<html><body><div id="div_Formaconsolidata" class="content_forma_act">'
        . '<span class="S_PAR"><span class="S_REF"><A href="~/../../../Public/DetaliiDocumentAfis/' . $to . '">COD FISCAL</A></span></span></div></body></html>';
    $references = ['2001' => '1001', '2002' => '1005', '2003' => '1002', '2004' => '1003'];

    Http::fake(static function (HttpRequest $request) use ($afis, $pages, $failing, $actListsItsOwn, $ownList, $reference, $references) {
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
                . '</div>' . ($actListsItsOwn !== null && $actListsItsOwn() ? $ownList : '') . '</body></html>');
        }

        if (str_contains($url, '/Public/DetaliiDocumentAfis/171282')) {
            return Http::response("<html><body>{$afis}</body></html>");
        }

        foreach ($references as $id => $to) {
            if (str_ends_with($url, "/Public/DetaliiDocument/{$id}")) {
                return Http::response($reference($to));
            }
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

it('drops a version an interrupted read left behind', function (): void {
    portal();
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $law = fiscalCode();

    // What a job killed between downloading a page and marking it read leaves: a version with no
    // consolidation, no dates, and its own sections.
    $orphan = SourceDocumentVersion::factory()->create(['source_document_id' => $law->id, 'consolidation_ref' => null]);
    \App\Domain\TaxKnowledge\Models\SourceSection::factory()->create(['document_version_id' => $orphan->id]);

    \App\Domain\TaxKnowledge\Jobs\FinishLegislationSync::dispatchSync($law->id);

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

    bootstrapIdentityCryptoForTests();
    Artisan::call('taxos:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    app(SyncLegislationDocument::class)('HOTARARE', 4, 2021, portalId: '236118');

    /** @var list<array<string, mixed>> $byNumber */
    $byNumber = $this->actingAs($admin)->getJson('/api/v1/legislation/portal?number=4&year=2021')->assertOk()->json('acts');

    expect(array_column($byNumber, 'portal_id'))->toBe(['236000', '236118'])
        ->and(array_column($byNumber, 'importable'))->toBe([true, true])
        ->and($byNumber[0]['law_id'])->toBeNull()
        ->and($byNumber[1]['law_id'])->toBe(SourceDocument::query()->where('portal_document_id', '236118')->sole()->id);

    // A year given to a title search is the act's own, compared here: 2020 has none of them.
    $this->actingAs($admin)->getJson('/api/v1/legislation/portal?title=salariului%20minim&year=2020')->assertOk()->assertJsonCount(0, 'acts');
    $this->actingAs($admin)->getJson('/api/v1/legislation/portal?title=ab')->assertStatus(422);
    $this->actingAs($admin)->getJson('/api/v1/legislation/portal?number=4')->assertStatus(422);
});

it('searches the portal only for those who may read a law in', function (): void {
    bootstrapIdentityCryptoForTests();
    Artisan::call('taxos:access:sync');

    $this->actingAs(User::factory()->create())->getJson('/api/v1/legislation/portal?title=codul%20fiscal')->assertForbidden();
});

it('imports the act chosen on the screen, through the task, to the end', function (): void {
    governmentDecisionPortal();
    bootstrapIdentityCryptoForTests();
    Artisan::call('taxos:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    // Exactly what the dialog sends: numbers as numbers. They reach the command as text, as they
    // would from a terminal — an integer here once failed the command's own check.
    $this->actingAs($admin)
        ->postJson('/api/v1/operations/tasks/legislation.ingest/run', ['act_type' => 'HOTARARE', 'act_number' => 4, 'act_year' => 2021, 'portal_id' => 236118])
        ->assertStatus(202);

    expect(SourceDocument::query()->where('portal_document_id', '236118')->sole()->external_identifier)->toBe('HOTARARE 4/2021');
});

it('filters a portal search to one type', function (): void {
    governmentDecisionPortal();
    bootstrapIdentityCryptoForTests();
    Artisan::call('taxos:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    $this->actingAs($admin)->getJson('/api/v1/legislation/portal?number=4&year=2021&type=LEGE')->assertOk()->assertJsonCount(0, 'acts');
    $this->actingAs($admin)->getJson('/api/v1/legislation/portal?number=4&year=2021&type=HOTARARE')->assertOk()->assertJsonCount(2, 'acts');
    $this->actingAs($admin)->getJson('/api/v1/legislation/portal?number=4&year=2021&type=DECRET')->assertStatus(422);
});

it('reads the act\'s own, longer list through its references, and reads again none already read', function (): void {
    $ownList = false;
    portal([], static function () use (&$ownList): bool {
        return $ownList;
    });
    app(SyncLegislationDocument::class)('LEGE', 227, 2015);
    $before = SourceDocumentVersion::query()->pluck('id', 'consolidation_ref')->all();

    // The law's own page lists one day more than the code's, 2018 — found once its list is read.
    $ownList = true;
    $started = app(SyncLegislationDocument::class)('LEGE', 227, 2015);

    $versions = SourceDocumentVersion::query()->where('source_document_id', fiscalCode()->id)->orderBy('effective_from')->get();

    expect([$started->listed, $started->toRead])->toBe([4, 1])
        ->and($versions->map(static fn (SourceDocumentVersion $v): array => [$v->consolidation_ref, $v->effective_from?->format('Y-m-d'), $v->effective_until?->format('Y-m-d')])->all())->toBe([
            ['2001', '2016-01-01', '2017-12-31'],
            ['2002', '2018-01-01', '2020-12-31'],
            ['2003', '2021-01-01', '2025-07-31'],
            ['2004', '2025-08-01', null],
        ])
        // Adopted, not read again: the same rows, renamed.
        ->and($versions->pluck('id')->intersect(array_values($before))->count())->toBe(3)
        ->and(fiscalCode()->canonical_url)->toEndWith('/Public/DetaliiDocument/171280');

    // The 2018 text is the code the reference pointed at: "8 salarii".
    expect($versions->get(1)?->sections()->where('citation_label', 'Art. 170 alin. (1)')->sole()->text)->toContain('8 salarii');
});
