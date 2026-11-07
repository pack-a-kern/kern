<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Settings\SystemHealthWatchTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Kern\Platform\Settings\DataTransferObjects\ComponentHealth;
use Kern\Platform\Settings\Events\SystemHealthChanged;
use Kern\Platform\Settings\Services\WatchSystemHealth;

/**
 * The strip that tells administrators a service stopped answering: kept by the scheduler, shared with
 * each page, pushed over the WebSocket when it changes — and seen by nobody else.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('keeps which services are down and announces the change', function (): void {
    Event::fake([SystemHealthChanged::class]);
    $watch = app(WatchSystemHealth::class);

    $down = $watch->record([
        ComponentHealth::healthy('database'),
        ComponentHealth::degraded('object_storage', 'UnableToCheckFileExistence'),
        ComponentHealth::notConfigured('mail'),
    ]);

    expect($down)->toBe(['object_storage'])
        ->and($watch->down())->toBe(['object_storage']);
    Event::assertDispatched(SystemHealthChanged::class, fn (SystemHealthChanged $event): bool => $event->down === ['object_storage']);
});

// Faked before the watch is resolved: it holds the dispatcher it was built with.
it('says a service that stays down once, not at every look', function (): void {
    Event::fake([SystemHealthChanged::class]);
    $watch = app(WatchSystemHealth::class);

    $watch->record([ComponentHealth::degraded('object_storage')]);
    $watch->record([ComponentHealth::degraded('object_storage')]);
    $watch->record([ComponentHealth::degraded('object_storage')]);

    Event::assertDispatchedTimes(SystemHealthChanged::class, 1);
});

it('announces the recovery too', function (): void {
    Event::fake([SystemHealthChanged::class]);
    $watch = app(WatchSystemHealth::class);

    $watch->record([ComponentHealth::degraded('object_storage')]);
    $watch->record([ComponentHealth::healthy('object_storage')]);

    expect($watch->down())->toBe([]);
    Event::assertDispatchedTimes(SystemHealthChanged::class, 2);
    Event::assertDispatched(SystemHealthChanged::class, fn (SystemHealthChanged $event): bool => $event->down === []);
});

// The queue it runs in is on `browser_net` (compose.services/browser.yaml), so the worker is asked like
// every other service. It used to be left out because the queue could not reach it.
it('counts the browser worker among the services it watches', function (): void {
    Config::set('browser_worker.url', 'http://browser-worker:8090');
    Http::fake(['http://browser-worker:8090/*' => Http::response(status: 500)]);

    expect(app(WatchSystemHealth::class)->watch())->toContain('browser_worker');
});

it('broadcasts the keys only, on the administrators’ channel', function (): void {
    $event = new SystemHealthChanged(['object_storage']);

    expect($event->broadcastOn()->name)->toBe('private-system.health')
        ->and($event->broadcastWith())->toBe(['down' => ['object_storage']]);
});

it('gives the kept answer to someone who may read the system status, and nothing to anyone else', function (): void {
    app(WatchSystemHealth::class)->record([ComponentHealth::degraded('object_storage')]);

    $this->actingAs(settingsAdminUser())->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('systemHealth.down', ['object_storage']));

    $this->actingAs(ordinaryUser())->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('systemHealth', null));
});

it('lets only someone who may read the system status join its channel', function (): void {
    $authorize = Broadcast::getChannels()[SystemHealthChanged::CHANNEL] ?? null;
    assert(is_callable($authorize), 'routes/channels.php registers no system.health channel');

    expect($authorize(settingsAdminUser()))->toBeTrue()
        ->and($authorize(ordinaryUser()))->toBeFalse();
});
