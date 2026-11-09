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
use Kern\Investments\Services\OrderSearch;
use Kern\Investments\Services\SubscriptionFiguresImport;
use Kern\Legislation\Jobs\ReadNextConsolidation;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Repositories\OfficialSourceRepository;

/** The portal's SOAP service, listing one Tezaur order for 2026 and nothing for any other year. */
function portal(): void
{
    $order = '<a:Legi><a:DataVigoare>2026-10-01</a:DataVigoare><a:Emitent>Ministerul Finanțelor</a:Emitent>'
        . '<a:LinkHtml>http://legislatie.just.ro/Public/DetaliiDocument/314972</a:LinkHtml><a:Numar>1324</a:Numar>'
        . '<a:Publicatie>Monitorul Oficial</a:Publicatie><a:Text>…</a:Text><a:TipAct>Ordin</a:TipAct>'
        . '<a:Titlu>ORDIN nr. 1.324 din 30 septembrie 2026 privind prospectul de emisiune a titlurilor de stat destinate populației, în cadrul Programului Tezaur, în perioada octombrie-noiembrie 2026</a:Titlu></a:Legi>';
    $envelope = static fn (string $inner): string => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $inner . '</s:Body></s:Envelope>';

    Http::fake(static function (Request $request) use ($order, $envelope) {
        if (str_contains($request->url(), '/apiws/')) {
            if (str_contains($request->header('SOAPAction')[0] ?? '', 'GetToken')) {
                return Http::response($envelope('<GetTokenResponse xmlns="http://tempuri.org/"><GetTokenResult>token</GetTokenResult></GetTokenResponse>'));
            }

            // The search by title, and the look-up by number the law's reading makes.
            $body = $request->body();
            $acts = str_contains($body, '<fws:NumarPagina>1</fws:NumarPagina>') && (str_contains($body, 'Tezaur') || str_contains($body, '1324')) ? $order : '';

            return Http::response($envelope('<SearchResponse xmlns="http://tempuri.org/"><SearchResult xmlns:a="http://schemas.datacontract.org/2004/07/FreeWebService">' . $acts . '</SearchResult></SearchResponse>'));
        }

        return Http::response((string) file_get_contents(__DIR__ . '/../Fixtures/orders/tezaur-prospectus.html'));
    });
}

beforeEach(function (): void {
    $this->travelTo('2026-11-09 03:00:00');
});

it('searches every year from each programme’s first on its first run, and reads a new order as a law', function (): void {
    portal();
    Queue::fake();

    $lines = app(OrderSearch::class)->run();

    expect($lines)->toHaveCount(7 + 9)
        ->and($lines)->toContain('TEZAUR 2026: 1 orders, 1 new read as laws, 0 already laws read for their issues')
        ->and(SourceDocument::query()->where('portal_document_id', '314972')->exists())->toBeTrue();
    Queue::assertPushed(ReadNextConsolidation::class, 1);
});

it('searches this year alone once a programme was read, and reads an order already a law for its issues', function (): void {
    portal();
    Queue::fake();
    $source = app(OfficialSourceRepository::class)->idForCode('LEGISLATIE');
    // Another Tezaur order was read before: this programme's first run is behind it.
    $earlier = SourceDocument::query()->create(['official_source_id' => $source, 'document_type' => 'ORDIN', 'external_identifier' => 'ORDIN 1/2026', 'canonical_url' => 'https://legislatie.just.ro/x']);
    TreasuryOrder::query()->create(['source_document_id' => $earlier->id, 'programme' => Programme::TEZAUR, 'issues_count' => 3, 'read_at' => now()]);
    // This one is a law already — added from the laws screen before this package — never read for its issues.
    $known = SourceDocument::query()->create(['official_source_id' => $source, 'document_type' => 'ORDIN', 'external_identifier' => 'ORDIN 1324/2026', 'canonical_url' => 'https://legislatie.just.ro/y', 'portal_document_id' => '314972']);
    SourceDocumentVersion::query()->create(['source_document_id' => $known->id, 'retrieved_at' => now(), 'content_sha256' => str_repeat('a', 64), 'raw_object_path' => 'x', 'size_bytes' => 1]);

    $lines = app(OrderSearch::class)->run();

    expect($lines)->toContain('TEZAUR 2026: 1 orders, 0 new read as laws, 1 already laws read for their issues')
        ->and(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'TEZAUR')))->toHaveCount(1);
    Queue::assertPushed(ReadTreasuryOrder::class, fn (ReadTreasuryOrder $job): bool => $job->sourceDocumentId === $known->id);
    Queue::assertNotPushed(ReadNextConsolidation::class);
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
