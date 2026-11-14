<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Http\InternalServiceCertificateTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\Documents\Extraction\PdfPageCounter;
use Kern\Legislation\Retrieval\Embedding\HttpEmbeddingProvider;
use Kern\Platform\Http\CertificateAuthority;
use Kern\Platform\Settings\Services\ProbeSystemHealth;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\StatementImport\Gateways\StatementParseGateway;
use RuntimeException;
use Throwable;

/*
 * Every call to a service inside the stack checks that service's certificate against the CA named in
 * config — the stack's own, which compose sets — and against the system's CAs when none is named.
 * Never against nothing: a client that does not check encrypts the connection to whoever answers it.
 */

const STACK_CA = '/run/kern/tls-ca/ca.crt';

beforeEach(function (): void {
    // The browser worker's client reads the Browser card's proxy before it calls.
    Artisan::call('migrate', ['--force' => true]);
    Http::preventStrayRequests();
    Config::set('services.processing.base_url', 'https://python-api:8000');
    Config::set('services.processing.auth_token', 'test-shared-secret');
    Config::set('browser_worker.url', 'https://browser-worker:8090');
    Config::set('browser_worker.auth_token', 'test-worker-token');
});

/**
 * What the client asked Guzzle to verify the certificate against, on the one request `$call` makes.
 *
 * The response is whatever the stub returns, so most clients go on to fail on it — which is not what
 * is under test, and is ignored.
 *
 * @param  callable(): mixed  $call
 */
function verifiedAgainst(callable $call): mixed
{
    $verify = 'no request was made';

    Http::fake(function (Request $request, array $options) use (&$verify) {
        $verify = $options['verify'] ?? 'not set';

        return Http::response([], 200);
    });

    try {
        $call();
    } catch (Throwable) {
        // The stub's empty answer is not a real one. The request was made, and that is what counts.
    }

    return $verify;
}

/** @return resource */
function pdf()
{
    return fopen('data://text/plain,%PDF-1.4', 'rb') ?: throw new RuntimeException('Unable to open a stream.');
}

/** The host a URL names. */
function hostOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_HOST);
}

/**
 * Each internal client, by what it calls and the config key naming that service's CA.
 *
 * @return array<string, array{string, callable(): mixed}>
 */
function internalClients(): array
{
    return [
        'document text extraction' => ['services.processing.ca_cert', static fn () => app(PdfPageCounter::class)->countPages(pdf())],
        'statement reading' => ['services.processing.ca_cert', static fn () => app(StatementParseGateway::class)->check('date,amount')],
        'legislation embeddings' => ['services.processing.ca_cert', static fn () => app(HttpEmbeddingProvider::class)->embed(['text'])],
        'the SPV browser worker' => ['browser_worker.ca_cert', static fn () => app(SpvBrowserSessionClient::class)->createSession()],
    ];
}

it('checks each service against the CA its config names', function (string $caKey, callable $call): void {
    Config::set($caKey, STACK_CA);

    expect(verifiedAgainst($call))->toBe(STACK_CA);
})->with(internalClients());

it('checks a service with no CA named against the system CAs, and never turns checking off', function (string $caKey, callable $call): void {
    Config::set($caKey, '');

    expect(verifiedAgainst($call))->toBeTrue();
})->with(internalClients());

it('checks the services the health page asks against their CA too', function (string $key, string $caKey): void {
    Storage::fake(Config::string('filesystems.default'));
    Config::set($caKey, STACK_CA);
    $seen = [];

    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[hostOf($request->url())] = $options['verify'] ?? 'not set';

        return Http::response([], 200);
    });

    app(ProbeSystemHealth::class)();

    expect($seen[hostOf(Config::string($key))] ?? 'not asked')->toBe(STACK_CA);
})->with([
    'the processing plane' => ['services.processing.base_url', 'services.processing.ca_cert'],
    'the browser worker' => ['browser_worker.url', 'browser_worker.ca_cert'],
]);

it('reads an unnamed or empty CA as the system CAs, not as checking switched off', function (mixed $configured, string|true $verify): void {
    Config::set('services.processing.ca_cert', $configured);

    expect(CertificateAuthority::verifyOption(config(), 'services.processing.ca_cert'))->toBe($verify);
})->with([
    'a file' => [STACK_CA, STACK_CA],
    'empty' => ['', true],
    'unset' => [null, true],
    'false, from a misread env file' => [false, true],
]);
