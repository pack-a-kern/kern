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

beforeEach(function (): void {
    // The probe asks every service at once, and only three of them are what these tests are about.
    // Faking the disk keeps the storage row from reaching a real MinIO, which does not exist in a
    // test run and whose client retries before it gives up — seconds this suite should not spend.
    Storage::fake(Config::string('filesystems.default'));

    // Both optional services are switched off here, so each test registers the one stub for the
    // service it is about and no request leaves the process by accident. Nothing is faked with a
    // `*` pattern, because the first *matching* stub wins: a wildcard registered here could never
    // be overridden by a test that wants a service to answer differently.
    Http::preventStrayRequests();
    Config::set('services.processing.base_url', null);
    Config::set('browser_worker.url', null);
});

/**
 * The two services an installation may leave out, and where each one's address comes from.
 *
 * One dataset rather than two sets of tests: `ProbeSystemHealth::probeOptionalHttpService()` is one
 * method serving both, so a case that holds for one and not the other would be a surprise worth
 * failing on.
 */
const OPTIONAL_SERVICES = [
    'the processing plane' => ['processing', 'services.processing.base_url', 'http://python-api:8000'],
    'the browser worker' => ['browser_worker', 'browser_worker.url', 'http://browser-worker:8090'],
];

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

        public function accessSecondsRemaining(): ?int
        {
            throw KeyManagementException::requestFailed('lookup-self', 503);
        }

        public function blindIndexes(array $normalizedValues): array
        {
            throw KeyManagementException::requestFailed('hmac', 503);
        }

        public function currentBlindIndexKeyVersion(): int
        {
            throw KeyManagementException::requestFailed('read-blind-index-key', 503);
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
    // Both ways a token can be given: a container that mounts the token file would otherwise build fine.
    Config::set('crypto.kms.openbao.token', null);
    Config::set('crypto.kms.openbao.token_file', null);
    app()->forgetInstance(KeyManagementService::class);

    $row = healthOf('key_management');

    // The binding's own refusal, not a failed request: this never left the process.
    expect($row->state)->toBe('degraded')
        ->and($row->detail)->toBe('InvalidArgumentException');
});

it('reports an optional service healthy when it answers', function (string $key, string $configKey, string $baseUrl): void {
    Config::set($configKey, $baseUrl);
    Http::fake([$baseUrl . '*' => Http::response(['status' => 'ok'])]);

    expect(healthOf($key)->state)->toBe('healthy');
})->with(OPTIONAL_SERVICES);

it('asks the processing plane its unauthenticated health route, sending no secret', function (): void {
    Config::set('services.processing.base_url', 'http://python-api:8000');
    Http::fake(['http://python-api:8000/*' => Http::response(['status' => 'ok'])]);

    healthOf('processing');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://python-api:8000/health'
        && ! $request->hasHeader('Authorization'));
});

it('asks the browser worker a route behind its token, with this installation\'s token', function (): void {
    // Its `/health` answers the same with a wrong token or none, so a worker refusing every SPV run
    // looked healthy here. `/v1/stats` sits behind the check.
    Config::set('browser_worker.url', 'http://browser-worker:8090');
    Config::set('browser_worker.auth_token', 'worker-secret');
    Http::fake(['http://browser-worker:8090/*' => Http::response(['active' => 0])]);

    expect(healthOf('browser_worker')->state)->toBe('healthy');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://browser-worker:8090/v1/stats'
        && $request->hasHeader('Authorization', 'Bearer worker-secret'));
});

it('reports the browser worker degraded when it refuses this installation\'s token', function (): void {
    Config::set('browser_worker.url', 'http://browser-worker:8090');
    Config::set('browser_worker.auth_token', '');
    Http::fake(['http://browser-worker:8090/*' => Http::response(['error' => 'unauthorized'], 401)]);

    expect(healthOf('browser_worker')->state)->toBe('degraded');

    Http::assertSent(fn ($request): bool => ! $request->hasHeader('Authorization'));
});

it('reports an optional service degraded when it answers with a failure', function (string $key, string $configKey, string $baseUrl): void {
    Config::set($configKey, $baseUrl);
    Http::fake([$baseUrl . '*' => Http::response(status: 503)]);

    $row = healthOf($key);

    expect($row->state)->toBe('degraded')
        ->and($row->detail)->toBe('RuntimeException');
})->with(OPTIONAL_SERVICES);

it('reports an optional service not configured when this installation does not run it', function (string $key, string $configKey, string $baseUrl): void {
    // `compose.services/python.yaml` and `compose.services/browser.yaml` are both files a
    // COMPOSE_FILE may leave out. Leaving one out is a decision, not a fault, and the board must
    // not read as broken because of it.
    Config::set($configKey, null);

    $row = healthOf($key);

    expect($row->state)->toBe('not_configured')
        ->and($row->detail)->toBeNull();

    // And nothing was asked. A `not_configured` row that still made a request would be reporting
    // one thing and doing another.
    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), $baseUrl));
})->with(OPTIONAL_SERVICES);

/*
 * Mail that goes to the log is not mail.
 *
 * The shipped default writes every message, verification codes included, into the log file — which
 * fails nothing, and so looked like a working mailer. An SMTP mailer is connected to and greeted.
 */
it('reports mail not configured when it is written to the log instead of sent, and says so', function (): void {
    // Not degraded: an installation with no mail server yet chooses this, and degraded fails the install.
    Config::set('mail.default', 'log');

    $row = healthOf('mail');

    expect($row->state)->toBe('not_configured')
        ->and($row->detail)->toContain('written to the log file');
});

it('reports mail healthy when its SMTP server greets it', function (): void {
    [$port, $server] = startStubServer(SMTP_GREETING);

    try {
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', '127.0.0.1');
        Config::set('mail.mailers.smtp.port', $port);

        expect(healthOf('mail')->state)->toBe('healthy');
    } finally {
        stopStubServer($server);
    }
});

it('reports mail degraded when its SMTP server does not answer', function (): void {
    // Port 9 on this host: nothing listens there, so the connection is refused at once.
    Config::set('mail.default', 'smtp');
    Config::set('mail.mailers.smtp.host', '127.0.0.1');
    Config::set('mail.mailers.smtp.port', 9);

    expect(healthOf('mail')->state)->toBe('degraded');
});

/**
 * A certificate list as docker/certs publishes it, one `<service> <end>` line each, in a temporary file.
 *
 * @param  array<string, string>  $ends  service => when its certificate ends, relative to now
 */
function certificateList(array $ends): string
{
    $file = tempnam(sys_get_temp_dir(), 'tls-status');
    $lines = array_map(
        static fn (string $service, string $end): string => $service . ' ' . (new DateTimeImmutable($end))->format('Y-m-d\TH:i:s\Z'),
        array_keys($ends),
        $ends,
    );
    file_put_contents($file, implode("\n", $lines) . "\n");

    return $file;
}

it('lists no certificates of its own when the stack publishes no list', function (): void {
    Config::set('crypto.tls.status_file', '');

    expect(healthOf('certificates')->state)->toBe('not_configured');
});

it('reports the certificates healthy, naming the first to end', function (): void {
    Config::set('crypto.tls.status_file', certificateList(['postgres' => '+300 days', 'redis' => '+200 days']));

    $row = healthOf('certificates');

    expect($row->state)->toBe('healthy')
        ->and($row->detail)->toContain('2 issued')
        ->and($row->detail)->toContain('redis');
});

it('reports the certificates degraded once one is near its end, because its renewal has stopped', function (): void {
    Config::set('crypto.tls.warn_days', 14);
    Config::set('crypto.tls.status_file', certificateList(['postgres' => '+300 days', 'edge' => '+10 days']));

    $row = healthOf('certificates');

    expect($row->state)->toBe('degraded')
        ->and($row->detail)->toContain('edge');
});

it('reports the certificates degraded when their list cannot be read', function (string $contents): void {
    $file = tempnam(sys_get_temp_dir(), 'tls-status');
    file_put_contents($file, $contents);
    Config::set('crypto.tls.status_file', $file);

    expect(healthOf('certificates')->state)->toBe('degraded');
})->with([
    'empty' => [''],
    'not a date' => ["postgres soon\n"],
]);
