<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\Exceptions\KeyManagementException;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use Kern\Platform\Settings\DataTransferObjects\ComponentHealth;
use Kern\Platform\Settings\Services\ProbeSystemHealth;
use Kern\Platform\Testing\InMemoryKeyManagementService;

beforeEach(function (): void {
    // The probe asks every service at once, and only two of them are what these tests are about.
    // Faking the disk and the processing plane keeps the other rows from reaching a real MinIO or a
    // real FastAPI — neither of which exists in a test run, and the S3 client retries before it
    // gives up, which is seconds this suite should not spend.
    //
    // No `*` stub: the first *matching* stub wins, so a wildcard registered here could never be
    // overridden by a test that wants the worker to answer differently. Each test below registers
    // its own `browser-worker` stub, and the patterns do not overlap.
    Storage::fake(Config::string('filesystems.default'));
    Http::preventStrayRequests();
    Http::fake(['python-api*' => Http::response(['status' => 'ok'])]);
});

/**
 * The row for one component, by key.
 *
 * Every test here calls `__invoke()` and looks at a single entry: the class deliberately has no
 * per-component entry point, because the endpoint it serves answers about all of them at once.
 */
function healthOf(string $key): ComponentHealth
{
    foreach (app(ProbeSystemHealth::class)() as $component) {
        if ($component->key === $key) {
            return $component;
        }
    }

    throw new RuntimeException("No health row for [{$key}].");
}

it('reports the key manager healthy, with the KEK version it is wrapping under', function (): void {
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $row = healthOf('key_management');

    expect($row->state)->toBe('healthy')
        ->and($row->detail)->toBe('KEK version 1');
});

it('reports the key manager degraded when it answers, but not with a version', function (): void {
    // What a sealed OpenBao (503) or a missing transit key (404) reaches this class as.
    app()->instance(KeyManagementService::class, new class () implements KeyManagementService {
        public function wrapDataKey(string $plaintextDek): WrappedDataKey
        {
            throw KeyManagementException::requestFailed('encrypt', 503);
        }

        public function unwrapDataKey(WrappedDataKey $wrapped): string
        {
            throw KeyManagementException::requestFailed('decrypt', 503);
        }

        public function currentKekVersion(): int
        {
            throw KeyManagementException::requestFailed('read-key', 503);
        }

        public function rotateKek(): int
        {
            throw KeyManagementException::requestFailed('rotate', 503);
        }
    });

    $row = healthOf('key_management');

    // The class name and nothing else: an exception message from this layer can carry the address
    // it was talking to, and BLUEPRINT.md §4.10 keeps that out of an HTTP response.
    expect($row->state)->toBe('degraded')
        ->and($row->detail)->toBe('KeyManagementException');
});

it('reports the key manager degraded when it cannot even be built', function (): void {
    // No token means the binding throws while assembling the driver, before any request is made.
    // That has to degrade this one row rather than fail the whole page — the page is what an
    // operator opens precisely when something is misconfigured.
    Config::set('crypto.kms.openbao.token', null);
    app()->forgetInstance(KeyManagementService::class);

    $row = healthOf('key_management');

    // The binding's own refusal, not a failed request: this never left the process.
    expect($row->state)->toBe('degraded')
        ->and($row->detail)->toBe('InvalidArgumentException');
});

it('reports the browser worker healthy when it answers its health route', function (): void {
    Config::set('browser_worker.url', 'http://browser-worker:8090');
    Http::fake(['browser-worker*' => Http::response(['status' => 'ok', 'service' => 'browser-worker'])]);

    expect(healthOf('browser_worker')->state)->toBe('healthy');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://browser-worker:8090/health'
        // `/health` is the worker's one unauthenticated route, so the shared secret is not sent.
        && ! $request->hasHeader('Authorization'));
});

it('reports the browser worker degraded when it answers with a failure', function (): void {
    Config::set('browser_worker.url', 'http://browser-worker:8090');
    Http::fake(['browser-worker*' => Http::response(status: 503)]);

    $row = healthOf('browser_worker');

    expect($row->state)->toBe('degraded')
        ->and($row->detail)->toBe('RuntimeException');
});

it('reports the browser worker not configured when this installation does not run one', function (): void {
    // `compose.services/browser.yaml` is one of the files a COMPOSE_FILE may leave out. Leaving it
    // out is a decision, not a fault, and the board must not read as broken because of it.
    Config::set('browser_worker.url', null);

    $row = healthOf('browser_worker');

    expect($row->state)->toBe('not_configured')
        ->and($row->detail)->toBeNull();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'browser-worker'));
});
