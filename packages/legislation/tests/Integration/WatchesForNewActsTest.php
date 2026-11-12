<?php

declare(strict_types=1);

namespace Kern\Legislation\Tests\Integration\WatchesForNewActsTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Kern\Legislation\Jobs\ReadNextConsolidation;
use Kern\Legislation\Models\IgnoredAct;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Watches\RunLawWatches;
use Kern\Legislation\Watches\WatchSettings;
use RuntimeException;

/**
 * The portal's SOAP service, listing one made-up order for 2026 to a search for "exemplul", and the
 * order's own page. `$failing` makes every search answer HTTP 500, as the service does at random.
 */
function portal(bool $failing = false, int $acts = 1): void
{
    $one = static fn (int $n): string => '<a:Legi><a:DataVigoare>2026-10-01</a:DataVigoare><a:Emitent>Ministerul Exemplului</a:Emitent>'
        . '<a:LinkHtml>http://legislatie.just.ro/Public/DetaliiDocument/' . (900000 + $n) . '</a:LinkHtml><a:Numar>' . (76 + $n) . '</a:Numar>'
        . '<a:Publicatie>Monitorul Oficial</a:Publicatie><a:Text>…</a:Text><a:TipAct>Ordin</a:TipAct>'
        . '<a:Titlu>ORDIN nr. ' . (76 + $n) . ' din 30 septembrie 2026 privind exemplul de ordin urmărit</a:Titlu></a:Legi>';
    $act = implode('', array_map($one, range(1, $acts)));
    $envelope = static fn (string $inner): string => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $inner . '</s:Body></s:Envelope>';

    Http::fake(static function (Request $request) use ($act, $envelope, $failing) {
        if (! str_contains($request->url(), '/apiws/')) {
            return Http::response('<html><body><div class="content_forma_act"><span class="S_ART"><span class="S_ART_TTL">Articolul 1</span><span class="S_ART_BDY"><span class="S_PAR">Exemplu.</span></span></span></div></body></html>');
        }

        if (str_contains($request->header('SOAPAction')[0] ?? '', 'GetToken')) {
            return Http::response($envelope('<GetTokenResponse xmlns="http://tempuri.org/"><GetTokenResult>token</GetTokenResult></GetTokenResponse>'));
        }

        if ($failing) {
            return Http::response('', 500);
        }

        $body = $request->body();
        $acts = str_contains($body, '<fws:NumarPagina>1</fws:NumarPagina>') && (str_contains($body, 'exemplul') || str_contains($body, '77')) ? $act : '';

        return Http::response($envelope('<SearchResponse xmlns="http://tempuri.org/"><SearchResult xmlns:a="http://schemas.datacontract.org/2004/07/FreeWebService">' . $acts . '</SearchResult></SearchResponse>'));
    });
}

/** @param array<string, mixed> $declared */
function run(array $declared = []): array
{
    config(['legislation.watches.declared' => $declared]);

    return app(RunLawWatches::class)(WatchSettings::read(config()));
}

/** A package's watch, made up. */
function declaration(array $changes = []): array
{
    return ['example.orders' => [
        'declared_by' => 'kern/example', 'search' => 'exemplul de ordin', 'act_type' => 'ORDIN',
        'title_pattern' => '/privind exemplul/u', 'first_year' => '2024', 'every_hours' => '168', ...$changes,
    ]];
}

beforeEach(function (): void {
    $this->travelTo('2026-11-09 03:05:00');
    Queue::fake();
});

it('mirrors a package’s watch, runs it every year from its first, and reads the new act as a law', function (): void {
    portal();

    $lines = run(declaration());
    $watch = LawWatch::query()->where('key', 'example.orders')->firstOrFail();

    expect($lines)->toBe(['example.orders: 1 acts found in 2024, 2025, 2026, 1 new read as laws'])
        ->and($watch->declared_by)->toBe('kern/example')
        ->and($watch->last_run_at?->toDateTimeString())->toBe('2026-11-09 03:05:00')
        ->and($watch->next_run_at->toDateTimeString())->toBe('2026-11-16 03:05:00')
        ->and(SourceDocument::query()->where('portal_document_id', '900001')->exists())->toBeTrue();
    Queue::assertPushed(ReadNextConsolidation::class, 1);
});

it('runs a watch only when it is due, and then this year alone, the act already a law skipped', function (): void {
    portal();
    run(declaration());

    $this->travel(1)->hours();
    expect(run(declaration()))->toBe([]);

    $this->travel(7)->days();
    expect(run(declaration()))->toBe(['example.orders: 1 acts found in 2026, 0 new read as laws, 1 already laws']);
    Queue::assertPushed(ReadNextConsolidation::class, 1);
});

it('keeps only the titles a watch’s pattern matches', function (): void {
    portal();

    expect(run(declaration(['title_pattern' => '/privind altceva/u'])))->toBe(['example.orders: 0 acts found in 2024, 2025, 2026, 0 new read as laws']);
});

it('takes a declaration’s new search, keeps a pause made on the screen, and removes a watch no longer declared', function (): void {
    portal();
    run(declaration());
    LawWatch::query()->where('key', 'example.orders')->update(['enabled' => false]);

    run(declaration(['search' => 'exemplul nou', 'every_hours' => '24']));
    $watch = LawWatch::query()->where('key', 'example.orders')->firstOrFail();

    expect($watch->search_words)->toBe('exemplul nou')
        ->and($watch->every_hours)->toBe(24)
        ->and($watch->enabled)->toBeFalse();

    run();
    expect(LawWatch::query()->count())->toBe(0);
});

it('tries a failed run again within the hour, and a first run that failed searches every year next time', function (): void {
    portal(failing: true);
    config(['sources.entries.LEGISLATIE.search_refused_retries' => 0]);

    $lines = run(declaration());
    $watch = LawWatch::query()->where('key', 'example.orders')->firstOrFail();

    expect($lines[0])->toContain('FAILED')
        ->and($watch->last_run_at)->toBeNull()
        ->and($watch->next_run_at->toDateTimeString())->toBe('2026-11-09 04:05:00')
        ->and($watch->last_summary['failure'] ?? null)->toBe('portal_search_down');
});

it('keeps what a run did as counts, and on each law it read in, the watch that found it', function (): void {
    portal();

    run(declaration());
    $watch = LawWatch::query()->where('key', 'example.orders')->firstOrFail();

    expect($watch->last_summary)->toBe([
        'years' => [2024, 2025, 2026], 'found' => 1, 'started' => 1, 'known' => 0, 'ignored' => 0,
        'waiting' => 0, 'failed' => 0, 'retries' => 0, 'failure' => null,
    ])
        ->and(SourceDocument::query()->where('portal_document_id', '900001')->value('found_by_watch_id'))->toBe($watch->id);
});

it('passes over an act a person asked the watches to ignore, and reads it again once read by hand', function (): void {
    portal();
    IgnoredAct::query()->create(['portal_document_id' => '900001', 'title' => 'ORDIN nr. 77 privind exemplul de ordin urmărit']);

    expect(run(declaration()))->toBe(['example.orders: 1 acts found in 2024, 2025, 2026, 0 new read as laws, 1 ignored'])
        ->and(SourceDocument::query()->where('portal_document_id', '900001')->exists())->toBeFalse();

    app(SyncLegislationDocument::class)('ORDIN', 77, 2026, null, '900001');

    expect(IgnoredAct::query()->count())->toBe(0)
        ->and(SourceDocument::query()->where('portal_document_id', '900001')->value('found_by_watch_id'))->toBeNull();
});

it('fails, naming the watch and its package, on a declaration that is wrong', function (array $changes, string $message): void {
    expect(fn () => run(declaration($changes)))->toThrow(RuntimeException::class, $message);
})->with([
    'no search' => [['search' => null], 'The law watch "example.orders" (kern/example): search is missing'],
    'a pattern that does not compile' => [['title_pattern' => '/exemplul(/u'], 'title_pattern must be a PCRE pattern'],
    'a first year to come' => [['first_year' => '2999'], 'first_year must be a whole number'],
    'no interval' => [['every_hours' => '0'], 'every_hours must be a whole number'],
]);

it('fails by name when its own settings are missing', function (): void {
    config(['legislation.watches.intervals' => null]);

    expect(fn () => WatchSettings::read(config()))->toThrow(RuntimeException::class, 'LEGISLATION_WATCHES_INTERVALS');
});

it('starts no more new acts a run than allowed, and reads the rest at the next run within the hour', function (): void {
    config(['legislation.watches.max_new_acts' => '1']);
    portal(acts: 2);

    $lines = run(declaration());
    $watch = LawWatch::query()->where('key', 'example.orders')->firstOrFail();

    expect($lines[0])->toContain('1 new read as laws; 1 left for the next run')
        ->and($watch->last_run_at)->toBeNull()
        ->and($watch->next_run_at->toDateTimeString())->toBe('2026-11-09 04:05:00');
});

it('fails by name when a watch cap is missing', function (string $key, string $variable): void {
    config(["legislation.watches.{$key}" => null]);

    expect(fn () => WatchSettings::read(config()))->toThrow(RuntimeException::class, $variable);
})->with([
    ['max_new_acts', 'LEGISLATION_WATCHES_MAX_NEW_ACTS'],
    ['max_per_person', 'LEGISLATION_WATCHES_MAX_PER_PERSON'],
]);
