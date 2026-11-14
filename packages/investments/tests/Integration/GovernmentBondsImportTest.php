<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Integration\GovernmentBondsImportTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Jobs\ReadTreasuryOrder;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\TreasuryOrder;
use Kern\Investments\Services\SubscriptionFiguresImport;
use Kern\Investments\Services\UnreadOrders;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Repositories\OfficialSourceRepository;

beforeEach(function (): void {
    $this->travelTo('2026-11-09 03:00:00');
});

it('queues an order already a law but never read for its issues, or read as having none, and no other', function (): void {
    Queue::fake();
    $source = app(OfficialSourceRepository::class)->idForCode('LEGISLATIE');
    $law = static fn (string $identifier, string $title): SourceDocument => SourceDocument::query()->create(['official_source_id' => $source, 'document_type' => 'ORDIN', 'external_identifier' => $identifier, 'canonical_url' => 'https://legislatie.just.ro/x', 'title' => $title]);
    $version = static fn (SourceDocument $document) => SourceDocumentVersion::query()->create(['source_document_id' => $document->id, 'retrieved_at' => now(), 'content_sha256' => str_repeat('a', 64), 'raw_object_path' => 'x', 'size_bytes' => 1, 'parser_version' => '1.7.0']);

    $unread = $law('ORDIN 1324/2026', 'ORDIN … în cadrul Programului Tezaur …');
    $version($unread);
    $read = $law('ORDIN 851/2026', 'ORDIN … în cadrul Programului Tezaur …');
    $version($read);
    TreasuryOrder::query()->create(['source_document_id' => $read->id, 'programme' => Programme::TEZAUR, 'issues_count' => 3, 'read_at' => now(), 'parser_version' => '1.7.0']);
    $none = $law('ORDIN 123/2026', 'ORDIN … în cadrul Programului Tezaur …');
    $version($none);
    TreasuryOrder::query()->create(['source_document_id' => $none->id, 'programme' => Programme::TEZAUR, 'issues_count' => 0, 'read_at' => now()]);
    $version($law('ORDIN 9/2026', 'ORDIN privind altceva'));
    // Still being read as a law: no version yet.
    $law('ORDIN 1528/2026', 'ORDIN … în cadrul Programului Tezaur …');

    expect(app(UnreadOrders::class)->queue())->toBe('orders: 2 already laws, never read for their issues, read as having none or read again as laws since, queued to be read');
    Queue::assertPushed(ReadTreasuryOrder::class, 2);
    Queue::assertPushed(ReadTreasuryOrder::class, fn (ReadTreasuryOrder $job): bool => $job->sourceDocumentId === $unread->id && $job->programme === Programme::TEZAUR);
    Queue::assertPushed(ReadTreasuryOrder::class, fn (ReadTreasuryOrder $job): bool => $job->sourceDocumentId === $none->id);
});

/** @param array<string, mixed> $overrides */
function issue(string $code, string $issued, array $overrides = []): FinancialInstrument
{
    return FinancialInstrument::query()->create([
        'kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::FIDELIS, 'symbol' => $code, 'isin' => null,
        'name' => "FIDELIS {$code}", 'issuer_name' => 'Ministerul Finanțelor', 'currency' => 'RON', 'issue_date' => $issued,
        'status' => InstrumentStatus::ACTIVE, ...$overrides,
    ]);
}

/** The Ministry's page and its two lists, each list read by the processing service into the real grid. */
function ministry(): void
{
    $grid = static fn (string $name): array => json_decode((string) file_get_contents(__DIR__ . "/../Fixtures/treasury/{$name}-grid.json"), true, flags: JSON_THROW_ON_ERROR);

    Http::fake(static fn (Request $request) => match (true) {
        $request->url() === 'https://mfinante.gov.ro/subscriptions' => Http::response('<a href="/static/TEZAUR2018-2026_06102026.xlsx">T</a><a href="/static/FIDELIS2020-2026_12102026.xlsx">F</a>'),
        str_ends_with($request->url(), '.xlsx') => Http::response('PK workbook ' . basename($request->url())),
        default => Http::response($grid(str_contains($request->body(), 'FIDELIS') ? 'fidelis' : 'tezaur')),
    });
}

it('gives each issue its subscription figures by its code, and by its month where the list repeats the code', function (): void {
    ministry();
    $single = issue('R2208A', '2020-08-01');
    // Listed twice: in February 2025 at its own figures, and again under March 2025.
    $repeated = issue('R2602A', '2025-02-10');
    $tezaur = issue('0001', '2018-07-01', ['programme' => Programme::TEZAUR, 'symbol' => null, 'issue_code' => '0001']);
    // The list writes 0217 as "2017": nothing is matched to it.
    $typo = issue('0217', '2026-06-01', ['programme' => Programme::TEZAUR, 'symbol' => null, 'issue_code' => '0217']);

    $lines = app(SubscriptionFiguresImport::class)->run();

    expect($single->refresh()->subscribed_value_minor)->toBe(56237680000)
        ->and($single->subscriptions_count)->toBe(5414)
        ->and($repeated->refresh()->subscriptions_count)->not->toBeNull()
        ->and($tezaur->refresh()->subscribed_value_minor)->toBe(78622563400)
        ->and($typo->refresh()->subscribed_value_minor)->toBeNull()
        ->and($lines)->toContain('FIDELIS subscriptions: 231 lines, 2 matched, 229 skipped as not matching for sure');
});

it('skips a repeated code it cannot tell apart, and a list it read before', function (): void {
    ministry();
    // Issued in neither month the list repeats it under.
    $unsure = issue('R2602A', '2025-06-01');

    app(SubscriptionFiguresImport::class)->run();
    $again = app(SubscriptionFiguresImport::class)->run();

    expect($unsure->refresh()->subscribed_value_minor)->toBeNull()
        ->and($again)->toBe(['FIDELIS subscriptions: unchanged since last read', 'TEZAUR subscriptions: unchanged since last read']);
});
