<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\LegislatieJustRoLiveFetchTest;

use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\Parsing\LegislatieJustRoHtmlParser;
use App\Domain\TaxKnowledge\Services\SourceIngestionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * A REAL fetch against the real `legislatie.just.ro`, through the actual, unmodified Milestone 2
 * allowlisted fetcher and this task's real HTML parser — not a fixture. Skipped unless
 * `LEGISLATIE_LIVE_TEST=1`, the same opt-in pattern
 * `tests/Feature/Support/KeyManagement/OpenBaoLiveIntegrationTest.php` and
 * `tests/Feature/Support/ObjectStore/EncryptedObjectStoreLiveMinioTest.php` use — CI and
 * `make test` do not make outbound internet calls by default.
 *
 * The URL (`Public/DetaliiDocumentAfis/47355`, the Constituția României) was fetched and manually
 * inspected while building this task — see `docs/handoff/018-legislation-ingestion.md` "Sources
 * consulted". This test asserts only STRUCTURAL properties (section counts, citation-label shape,
 * uniqueness) — never a specific article's real legal text — because asserting fabricated legal
 * content would violate `docs/AGENTS.md` §2 even inside a test.
 */
function liveLegislatieUnavailable(): bool
{
    return getenv('LEGISLATIE_LIVE_TEST') !== '1';
}

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');
});

it('fetches a real legislatie.just.ro document and parses genuine, well-formed structure', function (): void {
    $ingested = app(SourceIngestionService::class)->ingest(
        'LEGISLATIE',
        'https://legislatie.just.ro/Public/DetaliiDocumentAfis/47355',
        'LEGE',
    );

    $parsed = (new LegislatieJustRoHtmlParser())->parse($ingested->rawBytes);

    expect($parsed->topLevelSections)->not->toBeEmpty();

    $labels = [];
    $flatten = function (array $sections) use (&$flatten, &$labels): void {
        foreach ($sections as $section) {
            $labels[] = $section->citationLabel;
            $flatten($section->children);
        }
    };
    $flatten($parsed->topLevelSections);

    expect(array_unique($labels))->toHaveCount(count($labels));

    $firstArticle = $parsed->topLevelSections[0];
    expect($firstArticle->sectionType)->toBe(SectionType::ARTICOL)
        ->and($firstArticle->citationLabel)->toMatch('/^Art\. /')
        ->and(trim($firstArticle->text) !== '' || $firstArticle->children !== [])->toBeTrue();
})->skip(fn (): bool => liveLegislatieUnavailable(), 'Set LEGISLATIE_LIVE_TEST=1 to run against the real legislatie.just.ro.');
