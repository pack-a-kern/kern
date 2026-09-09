<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\SourceIngestionServiceTest;

use App\Domain\TaxKnowledge\Fetching\Exceptions\UnexpectedContentTypeException;
use App\Domain\TaxKnowledge\Models\OfficialSource;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Services\SourceIngestionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'sources.entries.TESTSRC' => [
            'trust_tier' => 'D',
            'base_url' => 'https://src-fixture.test',
            'allowed_hosts' => ['src-fixture.test'],
            'user_agent' => 'TaxOS-Test-Fetcher/1.0',
            'enabled' => true,
        ],
        'sources.storage.disk' => 's3',
        'sources.storage.prefix' => 'sources',
    ]);
});

it('mirrors the config entry into an official_sources row on first use', function (): void {
    Http::fake(['https://src-fixture.test/doc.xml' => Http::response('<a/>', 200, ['Content-Type' => 'application/xml'])]);

    app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/doc.xml', 'FX_DAILY');

    $source = OfficialSource::query()->where('code', 'TESTSRC')->firstOrFail();

    expect($source->trust_tier->value)->toBe('D')
        ->and($source->base_url)->toBe('https://src-fixture.test')
        ->and($source->enabled)->toBeTrue();
});

it('stores the raw bytes content-addressed and records a new source_document_version', function (): void {
    Http::fake(['https://src-fixture.test/doc.xml' => Http::response('<a>hello</a>', 200, ['Content-Type' => 'application/xml'])]);

    $result = app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/doc.xml', 'FX_DAILY');

    expect($result->isNewVersion)->toBeTrue()
        ->and($result->contentSha256)->toBe(hash('sha256', '<a>hello</a>'))
        ->and(SourceDocumentVersion::query()->count())->toBe(1);

    Storage::disk('s3')->assertExists($result->rawObjectPath);
    expect(Storage::disk('s3')->get($result->rawObjectPath))->toBe('<a>hello</a>');
});

it('is idempotent: ingesting byte-identical content twice creates no second version', function (): void {
    Http::fake(['https://src-fixture.test/doc.xml' => Http::response('<a>hello</a>', 200, ['Content-Type' => 'application/xml'])]);

    $first = app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/doc.xml', 'FX_DAILY');
    $second = app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/doc.xml', 'FX_DAILY');

    expect($first->isNewVersion)->toBeTrue()
        ->and($second->isNewVersion)->toBeFalse()
        ->and($second->sourceDocumentVersionId)->toBe($first->sourceDocumentVersionId)
        ->and(SourceDocumentVersion::query()->count())->toBe(1);
});

it('creates a second version when the content actually changes', function (): void {
    // A single Http::fake() call with a sequence, not two separate Http::fake() calls: Laravel
    // merges successive Http::fake() registrations rather than replacing them, so the
    // first-registered stub for a given URL always wins — a second Http::fake() call for the
    // same URL would silently be ignored (confirmed empirically while writing this test).
    Http::fake([
        'https://src-fixture.test/doc.xml' => Http::sequence()
            ->push('<a>version-one</a>', 200, ['Content-Type' => 'application/xml'])
            ->push('<a>version-two</a>', 200, ['Content-Type' => 'application/xml']),
    ]);

    $first = app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/doc.xml', 'FX_DAILY');
    $second = app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/doc.xml', 'FX_DAILY');

    expect($second->isNewVersion)->toBeTrue()
        ->and($second->sourceDocumentVersionId)->not->toBe($first->sourceDocumentVersionId)
        ->and(SourceDocumentVersion::query()->count())->toBe(2);

    // Milestone 3 (docs/handoff/018-legislation-ingestion.md): a plain, knowable lineage fact,
    // never an effective-date inference — see that version's own migration docblock.
    $secondVersion = SourceDocumentVersion::query()->findOrFail($second->sourceDocumentVersionId);
    expect($secondVersion->supersedes_version_id)->toBe($first->sourceDocumentVersionId);

    $firstVersion = SourceDocumentVersion::query()->findOrFail($first->sourceDocumentVersionId);
    expect($firstVersion->supersedes_version_id)->toBeNull();
});

it('rejects an HTML response when XML was expected — the exact BNR-homepage-redirect failure mode', function (): void {
    Http::fake(['https://src-fixture.test/doc.xml' => Http::response('<!doctype html><html><body>homepage</body></html>', 200, ['Content-Type' => 'text/html'])]);

    app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/doc.xml', 'FX_DAILY', expectXml: true);
})->throws(UnexpectedContentTypeException::class);

it('does not enforce an XML shape when expectXml is not requested', function (): void {
    Http::fake(['https://src-fixture.test/doc.html' => Http::response('<!doctype html><html>legislation text</html>', 200, ['Content-Type' => 'text/html'])]);

    $result = app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/doc.html', 'LEGISLATION');

    expect($result->isNewVersion)->toBeTrue();
});

it('distinguishes documents by external_identifier under the same source and type', function (): void {
    Http::fake([
        'https://src-fixture.test/2025.xml' => Http::response('<a>2025</a>', 200, ['Content-Type' => 'application/xml']),
        'https://src-fixture.test/2026.xml' => Http::response('<a>2026</a>', 200, ['Content-Type' => 'application/xml']),
    ]);

    $y2025 = app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/2025.xml', 'FX_YEARLY', '2025');
    $y2026 = app(SourceIngestionService::class)->ingest('TESTSRC', 'https://src-fixture.test/2026.xml', 'FX_YEARLY', '2026');

    expect($y2025->sourceDocumentVersionId)->not->toBe($y2026->sourceDocumentVersionId);
});
