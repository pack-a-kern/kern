<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use Kern\Platform\Http\StackService;

/*
 * Every call to a service inside the stack is built here: its address, its certificate check and
 * its token. The certificate check is the one that must never go missing — a client that skips it
 * encrypts the connection to whoever answers.
 */

beforeEach(function (): void {
    Config::set('services.processing', ['base_url' => 'https://python-api:8000/', 'auth_token' => 'processing-token', 'ca_cert' => '/run/kern/tls/ca.crt']);
    Config::set('browser_worker.url', 'https://browser-worker:8090');
    Config::set('browser_worker.auth_token', 'worker-token');
    Config::set('browser_worker.ca_cert', '/run/kern/tls/worker-ca.crt');
});

it('checks the certificate against the service\'s own CA, with its time limit and token', function (): void {
    $request = StackService::processing(config())->request(app(HttpFactory::class), 7);
    $options = $request->getOptions();

    expect($options['verify'])->toBe('/run/kern/tls/ca.crt')
        ->and($options['timeout'])->toBe(7)
        ->and($options['headers']['Authorization'] ?? null)->toBe('Bearer processing-token');
});

it('checks against the system\'s CAs when no CA file is named — never not at all', function (): void {
    Config::set('services.processing.ca_cert', '');

    expect(StackService::processing(config())->request(app(HttpFactory::class), 7)->getOptions()['verify'])->toBeTrue();
});

it('sends no token to a route that asks for none, and none it does not have', function (): void {
    $service = StackService::processing(config());

    expect($service->request(app(HttpFactory::class), 7, authenticated: false)->getOptions()['headers']['Authorization'] ?? null)->toBeNull();

    Config::set('services.processing.auth_token', '');
    $tokenless = StackService::processing(config());

    expect($tokenless->hasToken())->toBeFalse()
        ->and($tokenless->request(app(HttpFactory::class), 7)->getOptions()['headers']['Authorization'] ?? null)->toBeNull();
});

it('reads each service from its own settings', function (): void {
    $worker = StackService::browserWorker(config());

    expect($worker->url('/v1/sessions'))->toBe('https://browser-worker:8090/v1/sessions')
        ->and($worker->request(app(HttpFactory::class), 7)->getOptions()['verify'])->toBe('/run/kern/tls/worker-ca.crt')
        ->and(StackService::processing(config())->url('/v1/embeddings'))->toBe('https://python-api:8000/v1/embeddings');
});

it('says a service left out of this installation is not configured', function (): void {
    Config::set('browser_worker.url', null);

    expect(StackService::browserWorker(config())->isConfigured())->toBeFalse()
        ->and(StackService::processing(config())->isConfigured())->toBeTrue();
});
