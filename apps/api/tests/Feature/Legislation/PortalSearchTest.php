<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Kern\Legislation\Actions\StartPortalSearch;
use Kern\Legislation\Enums\PortalSearchStatus;
use Kern\Legislation\Events\PortalSearchChanged;
use Kern\Legislation\Jobs\ReadPortalSearchPage;
use Kern\Legislation\Legislatie\PortalQuery;
use Kern\Legislation\Models\PortalSearchRun;
use Kern\Platform\Identity\Models\User;

/*
 * A search of the portal, queued a page a job and read back by whoever started it (ADR 0024). Through the
 * browser here, the way the settings are on the installation this was built against; the service reads a
 * page the same way and is covered beside the import (`SyncLegislationDocumentTest`).
 */

const SEARCH_WORKER = 'http://browser-worker.test';

beforeEach(function (): void {
    config([
        'browser_worker.url' => SEARCH_WORKER,
        'browser_worker.auth_token' => 'test-token',
        'sources.entries.LEGISLATIE.search' => 'browser',
        // Ten a page here, so a page is short to write out; the site's fifty is the same rule.
        'sources.entries.LEGISLATIE.search_page_size' => 10,
    ]);
    Artisan::call('kern:access:sync');
    Queue::fake();
});

function searcher(): User
{
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');

    return $user;
}

/** @return list<array{title: string, in_force: string, link: string}> */
function ordersFrom(int $first, int $count): array
{
    return array_map(
        static fn (int $id): array => [
            'title' => "ORDIN nr. {$id} din 5 martie 2026\nprivind prospectul de emisiune a titlurilor de stat, în cadrul Programului Tezaur\nEMITENT: MINISTERUL FINANȚELOR\nPUBLICAT ÎN: MONITORUL OFICIAL nr. 1 din 6 martie 2026",
            'in_force' => 'Data intrarii in vigoare: 06 martie 2026',
            'link' => "/Public/DetaliiDocument/{$id}",
        ],
        range($first, $first + $count - 1),
    );
}

/**
 * The browser worker's search page, by the page asked for — `refused` for its error page, every time.
 *
 * @param  array<int, list<array<string, string>>|'refused'>  $pages
 */
function searchPages(array $pages): void
{
    Http::fake(static function (HttpRequest $request) use ($pages) {
        $url = $request->data()['secrets']['url'] ?? '';
        $page = preg_match('/[?&]page=(\d+)/', (string) $url, $match) === 1 ? (int) $match[1] : 1;
        $answer = $pages[$page] ?? [];

        return match (true) {
            $answer === 'refused' => Http::response(['outcome' => 'refused', 'data' => [], 'timing_ms' => 1]),
            $answer === [] => Http::response(['outcome' => 'none', 'data' => [], 'timing_ms' => 1]),
            default => Http::response(['outcome' => 'found', 'data' => ['acts' => $answer], 'timing_ms' => 1]),
        };
    });
}

it('answers a search at once, before the portal is asked anything, and reads it a page a job', function (): void {
    searchPages([1 => ordersFrom(1, 10), 2 => ordersFrom(11, 3)]);
    $user = searcher();

    $started = $this->actingAs($user)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programului Tezaur'])
        ->assertStatus(202);
    $id = portalSearchId($started);
    $started
        ->assertJsonPath('status', 'running')
        ->assertJsonPath('pages_read', 0)
        ->assertJsonPath('poll_after_ms', config()->integer('legislation.portal_search.poll_after_ms'));

    Http::assertNothingSent();
    Queue::assertPushed(ReadPortalSearchPage::class, static fn (ReadPortalSearchPage $job): bool => $job->page === 1
        && $job->queue === config()->string('legislation.portal_search.queue'));

    readPortalSearchPages();

    $this->actingAs($user)->getJson("/api/v1/legislation/portal-searches/{$id}")
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('pages_read', 2)
        ->assertJsonPath('poll_after_ms', null)
        ->assertJsonCount(13, 'acts')
        ->assertJsonPath('acts.0.type', 'ORDIN')
        ->assertJsonPath('acts.0.number', '1')
        ->assertJsonPath('acts.0.year', 2026)
        ->assertJsonPath('acts.0.importable', true);

    // The short second page was the last: nothing asked after it.
    Http::assertNotSent(static fn (HttpRequest $request): bool => str_contains($request->data()['secrets']['url'] ?? '', 'page=3'));
});

it('says after each page how far it got, on the owner\'s own channel and without the acts', function (): void {
    Event::fake([PortalSearchChanged::class]);
    searchPages([1 => ordersFrom(1, 10), 2 => ordersFrom(11, 3)]);
    $user = searcher();

    $this->actingAs($user)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programului Tezaur']);
    readPortalSearchPages();

    /** @var list<PortalSearchChanged> $events */
    $events = Event::dispatched(PortalSearchChanged::class)
        ->map(static fn (mixed $arguments): mixed => is_array($arguments) ? ($arguments[0] ?? null) : null)
        ->filter(static fn (mixed $event): bool => $event instanceof PortalSearchChanged)
        ->values()
        ->all();

    expect(array_map(static fn (PortalSearchChanged $event): array => [$event->status, $event->pagesRead, $event->found], $events))
        ->toBe([['running', 1, 10], ['done', 2, 13]])
        ->and($events[0]->broadcastOn())->toEqual([new PrivateChannel("users.{$user->id}")])
        ->and($events[0]->broadcastAs())->toBe('legislation.portal-search.changed')
        ->and(array_keys($events[0]->broadcastWith()))->toBe(['id', 'status', 'pages_read', 'found']);
});

it('broadcasts nothing for a search with no owner, or with the channel set empty', function (): void {
    $ownerless = PortalSearchRun::query()->create(['user_id' => null, 'query' => PortalQuery::byTitle('Tezaur')->toArray(), 'status' => PortalSearchStatus::RUNNING, 'pages_read' => 0, 'acts' => [], 'last_links' => []]);

    expect((new PortalSearchChanged($ownerless))->broadcastWhen())->toBeFalse();

    config(['legislation.portal_search.broadcast_channel' => '']);
    $owned = PortalSearchRun::query()->create(['user_id' => searcher()->id, 'query' => PortalQuery::byTitle('Tezaur')->toArray(), 'status' => PortalSearchStatus::RUNNING, 'pages_read' => 0, 'acts' => [], 'last_links' => []]);

    expect((new PortalSearchChanged($owned))->broadcastWhen())->toBeFalse();
});

it('ends incomplete at a later page that will not answer, keeping the pages before it and saying why', function (): void {
    Sleep::fake();
    searchPages([1 => ordersFrom(1, 10), 2 => 'refused']);
    $user = searcher();

    $id = portalSearchId($this->actingAs($user)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programului Tezaur']));
    readPortalSearchPages();

    $this->actingAs($user)->getJson("/api/v1/legislation/portal-searches/{$id}")
        ->assertJsonPath('status', 'incomplete')
        ->assertJsonPath('pages_read', 1)
        ->assertJsonPath('failure', 'portal_search_refused')
        ->assertJsonCount(10, 'acts');
});

it('retries a page through the queue, after the pauses the configuration gives', function (): void {
    config(['legislation.portal_search.retry_seconds' => [7, 21]]);

    $job = new ReadPortalSearchPage('01a1bd7e-ea89-701b-afd6-9c6ad267cd04', 2);

    expect($job->backoff())->toBe([7, 21])
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(config()->integer('legislation.portal_search.job_timeout_seconds'));
});

it('keeps only what the query asked for, and each act once', function (): void {
    $sameAgain = ordersFrom(1, 2);
    searchPages([1 => [...ordersFrom(1, 2), ...array_map(static fn (array $row): array => [...$row, 'title' => str_replace('5 martie 2026', '5 martie 2025', $row['title']), 'link' => $row['link'] . '0'], ordersFrom(3, 2)), ...$sameAgain]]);
    $user = searcher();

    $id = portalSearchId($this->actingAs($user)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programului Tezaur', 'year' => 2026, 'type' => 'ORDIN']));
    readPortalSearchPages();

    $this->actingAs($user)->getJson("/api/v1/legislation/portal-searches/{$id}")
        ->assertJsonPath('status', 'done')
        ->assertJsonCount(2, 'acts');
});

it('stops when cancelled: the next page is not read, and what was found is kept', function (): void {
    searchPages([1 => ordersFrom(1, 10), 2 => ordersFrom(11, 10)]);
    $user = searcher();

    $id = portalSearchId($this->actingAs($user)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programului Tezaur']));
    app()->call([portalSearchPagesPushed()->firstOrFail(), 'handle']);

    $this->actingAs($user)->deleteJson("/api/v1/legislation/portal-searches/{$id}")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonCount(10, 'acts');

    Http::fake();
    readPortalSearchPages();

    Http::assertNothingSent();
    expect(PortalSearchRun::query()->findOrFail($id)->status)->toBe(PortalSearchStatus::CANCELLED);
});

it('reads a page once, however often its job is delivered', function (): void {
    searchPages([1 => ordersFrom(1, 10), 2 => ordersFrom(11, 3)]);
    $user = searcher();

    $id = portalSearchId($this->actingAs($user)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programului Tezaur']));
    readPortalSearchPages();
    app()->call([new ReadPortalSearchPage($id, 1), 'handle']);

    expect(PortalSearchRun::query()->findOrFail($id))
        ->pages_read->toBe(2)
        ->acts->toHaveCount(13);
});

it('stops the owner\'s search still running when they start another', function (): void {
    $user = searcher();
    $first = portalSearchId($this->actingAs($user)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programului Tezaur']));
    $second = portalSearchId($this->actingAs($user)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programul FIDELIS']));

    expect(PortalSearchRun::query()->findOrFail($first)->status)->toBe(PortalSearchStatus::CANCELLED)
        ->and(PortalSearchRun::query()->findOrFail($second)->status)->toBe(PortalSearchStatus::RUNNING);
});

it('forgets every search older than it is kept, whoever started it', function (): void {
    $this->travel(-config()->integer('legislation.portal_search.keep_hours') - 1)->hours();
    $old = app(StartPortalSearch::class)(PortalQuery::byTitle('Programului Tezaur'), null);
    $this->travelBack();

    $new = app(StartPortalSearch::class)(PortalQuery::byTitle('Programul FIDELIS'), searcher()->id);

    expect(PortalSearchRun::query()->find($old->id))->toBeNull()
        ->and(PortalSearchRun::query()->find($new->id))->not->toBeNull();
});

it('answers a search to its owner only, as if anyone else\'s did not exist', function (): void {
    $owner = searcher();
    $id = portalSearchId($this->actingAs($owner)->postJson('/api/v1/legislation/portal-searches', ['title' => 'Programului Tezaur']));
    $someoneElse = searcher();

    $this->actingAs($someoneElse)->getJson("/api/v1/legislation/portal-searches/{$id}")->assertNotFound();
    $this->actingAs($someoneElse)->deleteJson("/api/v1/legislation/portal-searches/{$id}")->assertNotFound();
    expect(PortalSearchRun::query()->findOrFail($id)->status)->toBe(PortalSearchStatus::RUNNING);

    $this->actingAs(User::factory()->create())->getJson("/api/v1/legislation/portal-searches/{$id}")->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/legislation/portal-searches/{$id}")->assertUnauthorized();
});
