<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kern\Legislation\Models\UserAgent;
use Kern\Legislation\Services\UserAgentListImport;
use Kern\Platform\BrowserWorker\BrowserEngine;

it('keeps the list whole, and reads a list already read as nothing new', function (): void {
    Http::fake(['https://microlink.io/*' => Http::response((string) file_get_contents(__DIR__ . '/../Fixtures/UserAgents/list.json'))]);
    // A name last week's list gave and this week's does not: no longer offered.
    UserAgent::query()->create(['user_agent' => 'Mozilla/5.0 (Old) Chrome/120.0.0.0', 'engine' => BrowserEngine::CHROMIUM, 'system' => 'linux', 'browser' => 'chrome', 'version' => 120, 'published_at' => '2026-11-02 00:00:00']);

    $first = app(UserAgentListImport::class)();
    $again = app(UserAgentListImport::class)();

    expect($first->changed)->toBeTrue()
        ->and($first->kept)->toBe(10)
        ->and($again->changed)->toBeFalse()
        ->and(UserAgent::query()->count())->toBe(10)
        ->and(UserAgent::query()->where('user_agent', 'Mozilla/5.0 (Old) Chrome/120.0.0.0')->exists())->toBeFalse();
    Http::assertSentCount(2);
});

it('fetches the list only from the hosts its source allows', function (): void {
    config(['sources.entries.USER_AGENTS.endpoints.list' => 'https://elsewhere.example/user-agents.json']);
    Http::fake();

    expect(fn () => app(UserAgentListImport::class)())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});
