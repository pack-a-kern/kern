<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\PortalThroughBrowserTest;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Kern\Legislation\Fetching\Exceptions\FetchSizeExceededException;
use Kern\Legislation\Fetching\Exceptions\HostNotAllowedException;
use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;
use Kern\Legislation\Fetching\PageFetcher;
use Kern\Legislation\Legislatie\PortalSearch;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Platform\Identity\Models\User;

/**
 * legislatie.just.ro read through the browser worker, as the settings can choose: its search page for a
 * search, and a law's pages opened in the browser.
 *
 * The worker is faked at its API, answering as it does: the rows the playbook's `extract` reads off
 * `.search_result_item` — the title line as the page shows it, with the line break the page puts in
 * it — and, for a page, the bytes the server sent. What is tested is this side: the address each search
 * becomes, that both ways end in the same acts, that a page comes back to the same parser, and that
 * the portal itself is never asked directly while the settings say the browser.
 */
const WORKER = 'http://browser-worker.test';

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'browser_worker.url' => WORKER,
        'browser_worker.auth_token' => 'test-token',
        'sources.storage.disk' => 's3',
        'sources.storage.prefix' => 'sources',
        'sources.entries.LEGISLATIE.search' => 'browser',
        'sources.entries.LEGISLATIE.pages' => 'browser',
        'legislation.sync.retry_seconds' => [],
        'ai.embeddings.enabled' => false,
    ]);
    app()->forgetInstance(EmbeddingProvider::class);
});

/**
 * A row as the playbook extracts it from the results page.
 *
 * @return array{title: string, in_force: string, link: string}
 */
function row(string $title, string $inForce, string $id): array
{
    return ['title' => $title, 'in_force' => "Data intrarii in vigoare: {$inForce}", 'link' => "/Public/DetaliiDocument/{$id}"];
}

/**
 * The browser worker, answering the legislation playbook: `search` with the rows for an address,
 * `page` with the page served at one. Every request is recorded by `Http`, so a test can say what was
 * asked.
 *
 * @param  array<string, list<array<string, string>>|'refused'>  $results  by the query the address carries
 * @param  array<string, array{0: int, 1: string}>  $pages  by the path of the page asked for
 */
function worker(array $results = [], array $pages = []): void
{
    Http::fake(static function (HttpRequest $request) use ($results, $pages) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $url = $request->data()['secrets']['url'] ?? '';
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return match (true) {
            $path === '/v1/sessions' => Http::response(['session_id' => 's1', 'expires_at' => '2026-11-07T12:00:00+00:00'], 201),
            $path === '/v1/sessions/s1' && $request->method() === 'DELETE' => Http::response(['destroyed' => true]),
            $path === '/v1/sessions/s1/run' => match ($found = ($results[$query] ?? [])) {
                'refused' => Http::response(['outcome' => 'refused', 'data' => [], 'timing_ms' => 1]),
                [] => Http::response(['outcome' => 'none', 'data' => [], 'timing_ms' => 1]),
                default => Http::response(['outcome' => 'found', 'data' => ['acts' => $found], 'timing_ms' => 1]),
            },
            $path === '/v1/source' => (static function () use ($pages, $url) {
                [$status, $body] = $pages[(string) parse_url($url, PHP_URL_PATH)] ?? [404, ''];

                return $status === 200
                    ? Http::response($body, 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Source-Url' => $url])
                    : Http::response(['error' => 'source_status', 'status' => $status, 'url' => '[redacted]'], 502);
            })(),
            default => Http::response('', 404),
        };
    });
}

/**
 * The two decisions numbered 4 in 2021, as the site's search page lists them.
 *
 * @return array<string, list<array{title: string, in_force: string, link: string}>>
 */
function decisionsNumberedFour(): array
{
    return [
        'numardoc=4-2021' => [
            row("HOTĂRÂRE nr. 4 din 7 ianuarie 2021\nprivind aprobarea bugetului\n\nEMITENT: CONSILIUL LOCAL AL MUNICIPIULUI TEST\nPUBLICAT ÎN: MONITORUL OFICIAL nr. 30 din 11 ianuarie 2021", '11 ianuarie 2021', '236000'),
            row("HOTĂRÂRE nr. 4 din 13 ianuarie 2021\npentru stabilirea salariului de bază minim brut pe ţară garantat în plată\n\nEMITENT: GUVERNUL\nPUBLICAT ÎN: MONITORUL OFICIAL nr. 40 din 13 ianuarie 2021", '13 ianuarie 2021', '236118'),
        ],
    ];
}

it('searches the site by number in its own "numar-an" form, and reads each row as the service would have described it', function (): void {
    worker(decisionsNumberedFour());

    $acts = app(PortalSearch::class)->byNumber(4, 2021);

    expect(array_map(static fn ($act): ?string => $act->portalId(), $acts))->toBe(['236000', '236118']);

    $decision = $acts[1];

    expect([$decision->typeKey(), $decision->number, $decision->year()])->toBe(['HOTARARE', '4', 2021])
        ->and($decision->title)->toBe('HOTĂRÂRE nr. 4 din 13 ianuarie 2021 pentru stabilirea salariului de bază minim brut pe ţară garantat în plată')
        ->and($decision->issuer)->toBe('GUVERNUL')
        // Under the name the SOAP service gives it, not the capitals the page prints.
        ->and([$decision->officialJournal, $decision->officialJournalNumber, $decision->publishedOn?->format('Y-m-d')])->toBe(['Monitorul Oficial', '40', '2021-01-13'])
        ->and($decision->inForceFrom?->format('Y-m-d'))->toBe('2021-01-13');

    // One browser for the whole search, closed after it.
    Http::assertSentCount(3);
    Http::assertSent(static fn (HttpRequest $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/v1/sessions/s1'));
    Http::assertNotSent(static fn (HttpRequest $request): bool => str_contains($request->url(), 'legislatie.just.ro'));
});

it('narrows a title search by its year on the site, and compares the year on each act as well', function (): void {
    worker(['titlu=salariului+minim&semnatinceputtext=2021&semnatsfarsittext=2021' => [
        row("HOTĂRÂRE nr. 4 din 13 ianuarie 2021\npentru stabilirea salariului de bază minim brut\nEMITENT: GUVERNUL\nPUBLICAT ÎN: MONITORUL OFICIAL nr. 40 din 13 ianuarie 2021", '13 ianuarie 2021', '236118'),
        row("LEGE nr. 68 din 11 octombrie 1993\nprivind garantarea în plata a salariului minim\nEMITENT: PARLAMENTUL ROMÂNIEI\nPUBLICAT ÎN: MONITORUL OFICIAL nr. 246 din 15 octombrie 1993", '15 octombrie 1993', '1906'),
    ]]);

    $acts = app(PortalSearch::class)->byTitle('salariului minim', 2021);

    expect(array_map(static fn ($act): ?string => $act->portalId(), $acts))->toBe(['236118']);
});

it('imports an act found on the search page and read in the browser, the same as through the service', function (): void {
    worker(decisionsNumberedFour(), ['/Public/DetaliiDocument/236118' => [200, '<html><body><div id="div_Formaconsolidata" class="content_forma_act">'
        . '<span class="S_ART"><span class="S_ART_TTL">Articolul 1</span><span class="S_ART_BDY"><span class="S_PAR">Începând cu data de 14 ianuarie 2021, salariul de bază minim brut pe țară garantat în plată se stabilește la suma de 2.300 lei lunar.</span></span></span>'
        . '</div></body></html>']]);

    $started = app(SyncLegislationDocument::class)('HOTARARE', 4, 2021, portalId: '236118');
    $decision = SourceDocument::query()->where('external_identifier', 'HOTARARE 4/2021')->sole();
    $version = SourceDocumentVersion::query()->where('source_document_id', $decision->id)->sole();

    expect([$started->listed, $started->toRead])->toBe([1, 1])
        ->and($decision->issuer)->toBe('GUVERNUL')
        ->and($version->sections()->where('citation_label', 'Art. 1')->sole()->text)->toContain('2.300 lei lunar');

    Http::assertNotSent(static fn (HttpRequest $request): bool => str_contains($request->url(), 'legislatie.just.ro'));
});

it('keeps what the pages before found when the site refuses the page after the last', function (): void {
    $ten = static fn (int $from): array => array_map(
        static fn (int $id): array => row("LEGE nr. {$id} din 1 martie 2020\nprivind ceva\nEMITENT: PARLAMENTUL\nPUBLICAT ÎN: MONITORUL OFICIAL nr. 1 din 2 martie 2020", '2 martie 2020', (string) $id),
        range($from, $from + 9),
    );
    worker(['titlu=ceva' => $ten(1), 'titlu=ceva&page=2' => $ten(11), 'titlu=ceva&page=3' => 'refused']);

    expect(app(PortalSearch::class)->byTitle('ceva'))->toHaveCount(20);
});

it('stops asking for pages once the search has used its time, and answers with what it found', function (): void {
    $ten = array_map(
        static fn (int $id): array => row("LEGE nr. {$id} din 1 martie 2020\nprivind ceva\nEMITENT: PARLAMENTUL\nPUBLICAT ÎN: MONITORUL OFICIAL nr. 1 din 2 martie 2020", '2 martie 2020', (string) $id),
        range(1, 10),
    );
    worker(['titlu=ceva' => $ten]);
    config(['sources.entries.LEGISLATIE.search_deadline_seconds' => 0]);

    expect(app(PortalSearch::class)->byTitle('ceva'))->toHaveCount(10);
    Http::assertNotSent(static fn (HttpRequest $request): bool => str_contains($request->data()['secrets']['url'] ?? '', 'page=2'));
});

it('says the site refused the search, rather than that nothing matched, and closes the browser all the same', function (): void {
    worker(['titlu=codul+fiscal' => 'refused']);
    Artisan::call('kern:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    $this->actingAs($admin)->getJson('/api/v1/legislation/portal?title=codul%20fiscal')
        ->assertStatus(503)
        ->assertJsonPath('reason', 'portal_search_refused');

    Http::assertSent(static fn (HttpRequest $request): bool => $request->method() === 'DELETE');
});

it('says a busy browser as busy, not as a portal that is down', function (): void {
    Http::fake(['*' => Http::response(['error' => 'at_capacity'], 503)]);
    Artisan::call('kern:access:sync');
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');

    $this->actingAs($admin)->getJson('/api/v1/legislation/portal?title=codul%20fiscal')
        ->assertStatus(503)
        ->assertJsonPath('reason', 'portal_browser_busy');
});

it('holds a page read in the browser to the same rules as a download', function (): void {
    worker(pages: ['/Public/DetaliiDocument/1' => [404, ''], '/Public/DetaliiDocument/2' => [200, str_repeat('x', 64)]]);
    $pages = app(PageFetcher::class);

    // Off the allowlist: refused before the worker is asked.
    expect(fn () => $pages->fetch('LEGISLATIE', 'https://example.com/Public/DetaliiDocument/1'))->toThrow(HostNotAllowedException::class);
    Http::assertNothingSent();

    // A page the server refused is its status, which the import treats as it would a download's.
    try {
        $pages->fetch('LEGISLATIE', 'https://legislatie.just.ro/Public/DetaliiDocument/1');
        $refused = null;
    } catch (UnexpectedStatusException $exception) {
        $refused = $exception->status;
    }

    expect($refused)->toBe(404);

    // Past this installation's own ceiling, whatever the worker's is.
    config()->set('sources.fetch.max_response_bytes', 32);
    expect(fn () => $pages->fetch('LEGISLATIE', 'https://legislatie.just.ro/Public/DetaliiDocument/2'))->toThrow(FetchSizeExceededException::class);
});

it('never asks the browser while the settings say the service and a download', function (): void {
    config(['sources.entries.LEGISLATIE.search' => 'soap', 'sources.entries.LEGISLATIE.pages' => 'direct']);
    Http::fake(['*' => Http::response('<html></html>')]);

    app(PageFetcher::class)->fetch('LEGISLATIE', 'https://legislatie.just.ro/Public/DetaliiDocument/1');

    Http::assertNotSent(static fn (HttpRequest $request): bool => str_starts_with($request->url(), WORKER));
});
