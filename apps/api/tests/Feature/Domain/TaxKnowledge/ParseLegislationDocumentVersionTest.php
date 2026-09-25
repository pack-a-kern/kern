<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\ParseLegislationDocumentVersionTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\Legislation\Actions\ParseLegislationDocumentVersion;
use Kern\Legislation\Enums\SourceDocumentVersionStatus;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Services\SourceIngestionService;

/**
 * Wiring tests for the end-to-end fetch -> parse -> persist path, reusing the Milestone 2
 * fetcher/ingestion service (`docs/handoff/014-source-registry-bnr.md`) — this task builds no
 * second fetcher. The HTML below is a small SYNTHETIC fixture using the real, live-verified
 * legislatie.just.ro class-name conventions (see
 * tests/Unit/Domain/TaxKnowledge/LegislatieJustRoHtmlParserTest.php for the same convention used
 * in a pure unit test); the legal text itself is invented placeholder content.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'sources.entries.TESTLEG' => [
            'trust_tier' => 'A',
            'base_url' => 'https://legislatie-fixture.test',
            'allowed_hosts' => ['legislatie-fixture.test'],
            'user_agent' => 'Kern-Test-Fetcher/1.0',
            'enabled' => true,
            'priority' => 1,
        ],
        'sources.storage.disk' => 's3',
        'sources.storage.prefix' => 'sources',
    ]);
});

function syntheticActHtml(string $articleLabel = 'Articolul 1'): string
{
    return <<<HTML
        <html><body><div class="articole_wrapper">
        <span class="S_ART" id="id_art1">
          <span class="S_ART_TTL">{$articleLabel}</span>
          <span class="S_ART_BDY">SYNTHETIC: fixture article text.</span>
        </span>
        </div></body></html>
        HTML;
}

it('parses a version ingested through the reused Milestone 2 fetcher', function (): void {
    Http::fake(['https://legislatie-fixture.test/act.html' => Http::response(syntheticActHtml(), 200, ['Content-Type' => 'text/html'])]);

    $ingested = app(SourceIngestionService::class)->ingest('TESTLEG', 'https://legislatie-fixture.test/act.html', 'LEGE');
    $result = app(ParseLegislationDocumentVersion::class)($ingested->sourceDocumentVersionId);

    expect($result->sectionCount)->toBe(1)
        ->and($result->status)->toBe(SourceDocumentVersionStatus::REQUIRES_REVIEW)
        ->and(SourceSection::query()->count())->toBe(1);

    $section = SourceSection::query()->firstOrFail();
    expect($section->citation_label)->toBe('Art. 1')
        ->and($section->text)->toBe('SYNTHETIC: fixture article text.');
});

it('re-parsing the same version standalone replaces its sections without a new fetch', function (): void {
    Http::fake(['https://legislatie-fixture.test/act.html' => Http::response(syntheticActHtml(), 200, ['Content-Type' => 'text/html'])]);

    $ingested = app(SourceIngestionService::class)->ingest('TESTLEG', 'https://legislatie-fixture.test/act.html', 'LEGE');

    $first = app(ParseLegislationDocumentVersion::class)($ingested->sourceDocumentVersionId);
    expect($first->sectionCount)->toBe(1);

    $second = app(ParseLegislationDocumentVersion::class)($ingested->sourceDocumentVersionId);
    expect($second->sectionCount)->toBe(1)
        ->and(SourceSection::query()->count())->toBe(1);
});

it('accepts an explicit effective_from and marks the version PARSED', function (): void {
    Http::fake(['https://legislatie-fixture.test/act.html' => Http::response(syntheticActHtml(), 200, ['Content-Type' => 'text/html'])]);

    $ingested = app(SourceIngestionService::class)->ingest('TESTLEG', 'https://legislatie-fixture.test/act.html', 'LEGE');

    $result = app(ParseLegislationDocumentVersion::class)(
        $ingested->sourceDocumentVersionId,
        new \DateTimeImmutable('2026-01-01'),
    );

    expect($result->status)->toBe(SourceDocumentVersionStatus::PARSED);
});

it('links a second, content-changed version to the first via supersedes_version_id', function (): void {
    Http::fake([
        'https://legislatie-fixture.test/act.html' => Http::sequence()
            ->push(syntheticActHtml('Articolul 1'), 200, ['Content-Type' => 'text/html'])
            ->push(syntheticActHtml('Articolul 2'), 200, ['Content-Type' => 'text/html']),
    ]);

    $service = app(SourceIngestionService::class);
    $first = $service->ingest('TESTLEG', 'https://legislatie-fixture.test/act.html', 'LEGE');
    $second = $service->ingest('TESTLEG', 'https://legislatie-fixture.test/act.html', 'LEGE');

    expect($second->isNewVersion)->toBeTrue();

    $secondVersion = SourceDocumentVersion::query()->findOrFail($second->sourceDocumentVersionId);
    expect($secondVersion->supersedes_version_id)->toBe($first->sourceDocumentVersionId);

    $firstVersion = SourceDocumentVersion::query()->findOrFail($first->sourceDocumentVersionId);
    expect($firstVersion->supersedes_version_id)->toBeNull();
});
