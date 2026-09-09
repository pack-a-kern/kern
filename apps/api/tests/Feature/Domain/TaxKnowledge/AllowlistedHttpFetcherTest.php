<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\AllowlistedHttpFetcherTest;

use App\Domain\TaxKnowledge\Exceptions\SourceDisabledException;
use App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher;
use App\Domain\TaxKnowledge\Fetching\Exceptions\FetchSizeExceededException;
use App\Domain\TaxKnowledge\Fetching\Exceptions\HostNotAllowedException;
use App\Domain\TaxKnowledge\Fetching\Exceptions\TooManyRedirectsException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * `BLUEPRINT.md` §4.1 SSRF control. `TESTSRC`/`src-fixture.test` is a deliberately fake source code
 * and host that exists only in this test's own `config()` override — never a real BNR/ANAF/
 * legislatie.just.ro host — so this suite proves the generic allowlist mechanism without needing
 * (or risking looking like) a real endpoint.
 */
beforeEach(function (): void {
    config([
        'sources.entries.TESTSRC' => [
            'trust_tier' => 'D',
            'base_url' => 'https://src-fixture.test',
            'allowed_hosts' => ['src-fixture.test'],
            'user_agent' => 'TaxOS-Test-Fetcher/1.0',
            'enabled' => true,
        ],
        'sources.fetch.max_redirects' => 3,
        'sources.fetch.max_response_bytes' => 1024,
        'sources.fetch.timeout_seconds' => 5,
    ]);
});

it('sends the source-specific User-Agent and returns the body on success', function (): void {
    Http::fake(['https://src-fixture.test/doc.xml' => Http::response('<a/>', 200, ['Content-Type' => 'application/xml'])]);

    $fetched = app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'https://src-fixture.test/doc.xml');

    expect($fetched->body)->toBe('<a/>')
        ->and($fetched->statusCode)->toBe(200);

    Http::assertSent(function ($request): bool {
        return $request->header('User-Agent')[0] === 'TaxOS-Test-Fetcher/1.0';
    });
});

it('rejects a plain-http URL without making any request', function (): void {
    Http::fake();

    app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'http://src-fixture.test/doc.xml');
})->throws(HostNotAllowedException::class);

it('rejects a host not on the allowlist without making any request', function (): void {
    Http::fake();

    try {
        app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'https://evil.example/doc.xml');
    } catch (HostNotAllowedException) {
        Http::assertNothingSent();

        return;
    }

    Assert::fail('Expected HostNotAllowedException was not thrown.');
});

it('follows a redirect to an allowed host', function (): void {
    Http::fake([
        'https://src-fixture.test/redirect' => Http::response('', 302, ['Location' => 'https://src-fixture.test/final.xml']),
        'https://src-fixture.test/final.xml' => Http::response('<a/>', 200),
    ]);

    $fetched = app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'https://src-fixture.test/redirect');

    expect($fetched->body)->toBe('<a/>')
        ->and($fetched->finalUrl)->toBe('https://src-fixture.test/final.xml');
});

it('refuses to follow a redirect to a host off the allowlist', function (): void {
    Http::fake([
        'https://src-fixture.test/redirect' => Http::response('', 302, ['Location' => 'https://evil.example/steal.xml']),
        'https://evil.example/*' => Http::response('should never be requested', 200),
    ]);

    try {
        app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'https://src-fixture.test/redirect');
    } catch (HostNotAllowedException) {
        Http::assertNotSent(function ($request): bool {
            return str_contains((string) $request->url(), 'evil.example');
        });

        return;
    }

    Assert::fail('Expected HostNotAllowedException was not thrown.');
});

it('throws after exceeding the configured maximum number of redirects', function (): void {
    Http::fake([
        'https://src-fixture.test/hop-0' => Http::response('', 302, ['Location' => 'https://src-fixture.test/hop-1']),
        'https://src-fixture.test/hop-1' => Http::response('', 302, ['Location' => 'https://src-fixture.test/hop-2']),
        'https://src-fixture.test/hop-2' => Http::response('', 302, ['Location' => 'https://src-fixture.test/hop-3']),
        'https://src-fixture.test/hop-3' => Http::response('', 302, ['Location' => 'https://src-fixture.test/hop-4']),
        'https://src-fixture.test/hop-4' => Http::response('', 302, ['Location' => 'https://src-fixture.test/hop-5']),
    ]);

    app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'https://src-fixture.test/hop-0');
})->throws(TooManyRedirectsException::class);

it('throws when the response exceeds the configured size limit', function (): void {
    Http::fake([
        'https://src-fixture.test/huge.xml' => Http::response(str_repeat('a', 2048), 200),
    ]);

    app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'https://src-fixture.test/huge.xml');
})->throws(FetchSizeExceededException::class);

it('throws when the declared Content-Length exceeds the configured size limit', function (): void {
    Http::fake([
        'https://src-fixture.test/huge.xml' => Http::response('small body', 200, ['Content-Length' => '999999']),
    ]);

    app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'https://src-fixture.test/huge.xml');
})->throws(FetchSizeExceededException::class);

it('refuses to fetch from a disabled source', function (): void {
    config(['sources.entries.TESTSRC.enabled' => false]);
    Http::fake();

    app(AllowlistedHttpFetcher::class)->fetch('TESTSRC', 'https://src-fixture.test/doc.xml');
})->throws(SourceDisabledException::class);
