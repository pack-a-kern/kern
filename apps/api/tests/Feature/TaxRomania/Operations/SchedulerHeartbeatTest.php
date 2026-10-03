<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Kern\TaxRomania\Operations\Services\ProbeSchedulerStatus;

/**
 * The scheduler heartbeat must survive the container boundary.
 *
 * The first version of this probe read the mtime of `/tmp/scheduler-heartbeat` directly. That file
 * is real and fresh — but it lives in the `scheduler` container's own tmpfs, and this probe runs in
 * `app`, which has a different one. The screen reported a perfectly healthy scheduler as stalled.
 * These tests exercise the cross-container channel, so a return to a file-only read fails here
 * rather than on the screen.
 */
it('reads the heartbeat from the cache, which is the only channel both containers share', function (): void {
    $key = Config::string('operations.scheduler.heartbeat_cache_key');

    Cache::put($key, CarbonImmutable::now('UTC')->toIso8601String(), 3600);

    $status = app(ProbeSchedulerStatus::class)();

    expect($status->alive)->toBeTrue()
        ->and($status->ageSeconds)->toBeLessThanOrEqual(1)
        ->and($status->lastBeatAt)->not->toBeNull();
});

it('reports a stale heartbeat as stalled, with the age still readable', function (): void {
    $key = Config::string('operations.scheduler.heartbeat_cache_key');
    $maxAge = Config::integer('operations.scheduler.heartbeat_max_age_seconds');

    Cache::put($key, CarbonImmutable::now('UTC')->subSeconds($maxAge * 4)->toIso8601String(), 3600);

    $status = app(ProbeSchedulerStatus::class)();

    // A stored timestamp rather than a self-expiring marker, so the screen can say how long the
    // stall has lasted instead of only that one exists.
    expect($status->alive)->toBeFalse()
        ->and($status->ageSeconds)->toBeGreaterThanOrEqual($maxAge * 4);
});

it('never reports a scheduler with no heartbeat at all as alive', function (): void {
    Cache::forget(Config::string('operations.scheduler.heartbeat_cache_key'));
    Config::set('operations.scheduler.heartbeat_path', '/nonexistent/scheduler-heartbeat');

    $status = app(ProbeSchedulerStatus::class)();

    expect($status->alive)->toBeFalse()
        ->and($status->lastBeatAt)->toBeNull()
        ->and($status->ageSeconds)->toBeNull();
});

it('falls back to the local file when running in the scheduler container itself', function (): void {
    Cache::forget(Config::string('operations.scheduler.heartbeat_cache_key'));

    $path = sys_get_temp_dir() . '/heartbeat-probe-' . bin2hex(random_bytes(4));
    touch($path);
    Config::set('operations.scheduler.heartbeat_path', $path);

    try {
        expect(app(ProbeSchedulerStatus::class)()->alive)->toBeTrue();
    } finally {
        @unlink($path);
    }
});

it('writes both heartbeats when the scheduled entry runs', function (): void {
    // The schedule closure in bootstrap/app.php is the single writer for both audiences. Running
    // the registered entry proves the file and the cache key are updated together, rather than
    // asserting on a copy of the closure's logic.
    $key = Config::string('operations.scheduler.heartbeat_cache_key');
    Cache::forget($key);

    $path = sys_get_temp_dir() . '/heartbeat-entry-' . bin2hex(random_bytes(4));
    Config::set('operations.scheduler.heartbeat_path', $path);

    // `withSchedule()` hooks `Artisan::starting`, so the schedule stays empty until the console
    // application exists — running any command is what populates it. Without this the assertions
    // below would pass vacuously against zero events.
    Artisan::call('schedule:list');

    $heartbeat = collect(app(Schedule::class)->events())
        ->first(static fn (object $event): bool => $event->description === 'scheduler-heartbeat');

    expect($heartbeat)->not->toBeNull();

    try {
        $heartbeat?->run(app());

        $stored = Cache::get($key);
        expect($stored)->toBeString();
        // Narrowed for static analysis after the assertion above; `Cache::get()` returns mixed.
        $stored = is_string($stored) ? $stored : '';

        // Asserted to be a *non-numeric* string, which is the property that makes this survive
        // Redis. Laravel's Redis cache store passes numeric values through unserialized, so an
        // integer written here would be read back as the string "1788949200" — and the strict
        // read on the other side would discard it, reporting a healthy scheduler as stalled.
        // These tests run on the array store, which preserves an int faithfully and would
        // therefore never catch that. This assertion does.
        expect(is_file($path))->toBeTrue()
            ->and(is_numeric($stored))->toBeFalse()
            ->and(CarbonImmutable::parse($stored)->getTimestamp())
            ->toBeGreaterThan(CarbonImmutable::now('UTC')->subMinute()->getTimestamp());
    } finally {
        @unlink($path);
    }
});

it('degrades to stalled rather than failing the whole overview', function (): void {
    // Every other probe on the overview already degrades its own row. This one did not, so a throw
    // here returned 500 for the entire page — the scheduler card, the queue counts, data freshness
    // and the service list all lost together. A status board must not go dark because one signal is
    // unreadable.
    // The probe takes its cache by constructor injection, so it is built directly with a throwing
    // one rather than by mocking the facade — which the container itself depends on.
    /** @var CacheRepository $cache */
    $cache = Mockery::mock(CacheRepository::class)
        ->shouldReceive('get')
        ->andThrow(new RuntimeException('cache is unreachable'))
        ->getMock();

    Config::set('operations.scheduler.heartbeat_path', '/nonexistent/scheduler-heartbeat');

    $status = (new ProbeSchedulerStatus(app(ConfigRepository::class), $cache))();

    expect($status->alive)->toBeFalse()
        ->and($status->lastBeatAt)->toBeNull();
});
