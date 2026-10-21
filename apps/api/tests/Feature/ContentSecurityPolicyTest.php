<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;

/*
 * `App\Http\Middleware\ContentSecurityPolicy` and the endpoint its reports go to. The pages read
 * settings, so they need the schema — see HomePageTest for why this suite says so itself.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('sends the policy as report-only by default, allowing this request\'s nonce and no other inline script', function (): void {
    $response = $this->get(route('home'))->assertOk();

    $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($policy)->toContain("script-src 'self' 'nonce-" . Vite::cspNonce() . "'")
        ->and($policy)->not->toContain("'unsafe-inline'")
        ->and($policy)->not->toContain("'unsafe-eval'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain('report-uri /csp-report');
});

it('uses a fresh nonce on every page', function (): void {
    $first = (string) $this->get(route('home'))->headers->get('Content-Security-Policy-Report-Only');
    $second = (string) $this->get(route('home'))->headers->get('Content-Security-Policy-Report-Only');

    expect($first)->not->toBe($second);
});

it('names the socket\'s origin, which \'self\' does not cover in every browser', function (): void {
    $policy = (string) $this->get('https://kern.example/')->headers->get('Content-Security-Policy-Report-Only');

    expect($policy)->toContain("connect-src 'self' wss://kern.example");
});

it('enforces the policy once told to', function (): void {
    Config::set('csp.mode', 'enforce');

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeTrue()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('sends nothing when off', function (): void {
    Config::set('csp.mode', 'off');

    $response = $this->get(route('home'))->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('refuses a mode it does not know, rather than guessing one', function (): void {
    Config::set('csp.mode', 'enforcing');

    $this->withoutExceptionHandling()->get(route('home'));
})->throws(InvalidArgumentException::class);

it('puts no policy on a JSON response, which is never rendered', function (): void {
    $response = $this->getJson('/api/v1/settings/ai/providers');

    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('logs a reported violation without a CSRF token, reduced to the rule and the source', function (): void {
    /** @var list<array<string, mixed>> $logged */
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        if ($event->message === 'csp_violation') {
            $logged[] = $event->context;
        }
    });

    $this->call('POST', '/csp-report', server: ['CONTENT_TYPE' => 'application/csp-report'], content: json_encode([
        'csp-report' => [
            'document-uri' => 'https://kern.example/documents/review?token=secret-in-a-query',
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/x.js?session=abc',
            'source-file' => 'https://kern.example/build/assets/app.js',
            'line-number' => 12,
        ],
    ], JSON_THROW_ON_ERROR))->assertNoContent();

    expect($logged)->toBe([[
        'directive' => 'script-src-elem',
        'blocked' => 'https://evil.example/x.js',
        'page' => 'https://kern.example/documents/review',
        'source' => 'https://kern.example/build/assets/app.js',
        'line' => 12,
    ]]);
});

it('accepts a body that is not a report without logging anything', function (): void {
    $logged = 0;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged += $event->message === 'csp_violation' ? 1 : 0;
    });

    $this->call('POST', '/csp-report', content: 'not json')->assertNoContent();

    expect($logged)->toBe(0);
});

it('writes the same nonce into the page, for the scripts that insert style elements of their own', function (): void {
    $response = $this->get(route('home'))->assertOk();

    $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

    expect($response->getContent())->toContain('<meta property="csp-nonce" nonce="' . Vite::cspNonce() . '">')
        ->and($policy)->toContain("style-src-elem 'self' 'nonce-" . Vite::cspNonce() . "'");
});

it('fills in every placeholder in the configured rules, and leaves none behind', function (): void {
    $policy = (string) $this->get(route('home'))->headers->get('Content-Security-Policy-Report-Only');

    expect($policy)->not->toMatch('/\{[a-z-]+\}/');
});
