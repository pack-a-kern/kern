<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Integration\TreasuryOrdersTest;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Events\IssueOnOffer;
use Kern\Investments\Jobs\ReadTreasuryOrder;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\TreasuryOrder;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Services\TreasuryOrders;
use Kern\Investments\Services\UnreadOrders;
use Kern\Legislation\Events\LawConsolidationsRead;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;
use Kern\Legislation\Queries\LawFlags;
use Kern\Legislation\Repositories\OfficialSourceRepository;
use Kern\Legislation\Services\LegislationSectionPersister;
use Kern\Legislation\Storage\RawSourceObjectStore;

/** A real order, kept as kern/legislation keeps one it has read: the document, its page stored, and its provisions. */
function storedOrder(string $fixture, string $title, array $changes = []): SourceDocument
{
    $page = strtr((string) file_get_contents(__DIR__ . "/../Fixtures/orders/{$fixture}.html"), $changes);
    $stored = app(RawSourceObjectStore::class)->store('LEGISLATIE', $page);
    $order = SourceDocument::query()->create([
        'official_source_id' => app(OfficialSourceRepository::class)->idForCode('LEGISLATIE'),
        'document_type' => 'ORDIN',
        'external_identifier' => 'ORDIN ' . md5($fixture . serialize($changes)),
        'canonical_url' => 'https://legislatie.just.ro/Public/DetaliiDocument/1',
        'title' => $title,
        'issuer' => 'Ministerul Finanțelor',
    ]);
    $version = SourceDocumentVersion::query()->create([
        'source_document_id' => $order->id,
        'retrieved_at' => now(),
        'content_sha256' => $stored->sha256,
        'raw_object_path' => $stored->path,
        'size_bytes' => strlen($page),
    ]);
    app(LegislationSectionPersister::class)->persist($version->id, (new LegislatieJustRoHtmlParser())->parse($page));

    return $order;
}

beforeEach(function (): void {
    $this->travelTo('2026-10-05 10:00:00');
});

it('writes a Fidelis order’s issues and their payouts, and tells which is on offer', function (): void {
    Event::fake([IssueOnOffer::class]);
    $order = storedOrder('fidelis-terms', 'ORDIN … Programul FIDELIS');

    app(TreasuryOrders::class)->read($order->id, Programme::FIDELIS);

    $issue = FinancialInstrument::query()->where('isin', 'ROQEJCC1VXP7')->firstOrFail();
    $coupons = InstrumentPayout::query()->where('instrument_id', $issue->id)->where('kind', PayoutKind::COUPON)->orderBy('payment_date')->get();

    expect(FinancialInstrument::query()->count())->toBe(7)
        ->and($issue->symbol)->toBe('R2810B')
        ->and($issue->status)->toBe(InstrumentStatus::ON_OFFER)
        ->and($issue->rate)->toBe('7.60')
        ->and($issue->terms_document_id)->toBe($order->id)
        ->and($coupons->pluck('amount_per_unit')->all())->toBe(['7.600000', '7.600000'])
        ->and($coupons->first()?->record_date?->format('Y-m-d'))->toBe('2027-10-05')
        ->and(TreasuryOrder::query()->where('source_document_id', $order->id)->value('issues_count'))->toBe(7);
    Event::assertDispatchedTimes(IssueOnOffer::class, 7);
});

it('writes a Tezaur prospectus’ issues with each coupon’s period and only its rate', function (): void {
    $order = storedOrder('tezaur-prospectus', 'ORDIN … în cadrul Programului Tezaur');

    app(TreasuryOrders::class)->read($order->id, Programme::TEZAUR);

    $issue = FinancialInstrument::query()->where('programme', Programme::TEZAUR)->where('issue_code', '0229')->firstOrFail();
    $payouts = InstrumentPayout::query()->where('instrument_id', $issue->id)->orderBy('payment_date')->get();

    expect($issue->isin)->toBeNull()
        ->and($issue->market)->toBeNull()
        ->and($payouts)->toHaveCount(4)
        ->and($payouts[0]->amount_per_unit)->toBeNull()
        ->and($payouts[0]->rate)->toBe('7.00')
        ->and($payouts[0]->period_start?->format('Y-m-d'))->toBe('2026-11-10')
        ->and($payouts[3]->kind)->toBe(PayoutKind::PRINCIPAL)
        ->and($payouts[3]->amount_per_unit)->toBe('1');
});

it('reads an order once: read again, it changes nothing', function (): void {
    $order = storedOrder('tezaur-prospectus', 'ORDIN … în cadrul Programului Tezaur');

    app(TreasuryOrders::class)->read($order->id, Programme::TEZAUR);
    app(TreasuryOrders::class)->read($order->id, Programme::TEZAUR);

    expect(FinancialInstrument::query()->count())->toBe(3)
        ->and(InstrumentPayout::query()->count())->toBe(12)
        ->and(TreasuryOrder::query()->count())->toBe(1);
});

it('leaves a bond another order wrote as it is, and says beside the order how many it skipped', function (): void {
    $first = storedOrder('fidelis-terms', 'ORDIN … Programul FIDELIS');
    $second = storedOrder('fidelis-terms', 'ORDIN … Programul FIDELIS', ['7,60% pe an' => '9,99% pe an']);

    app(TreasuryOrders::class)->read($first->id, Programme::FIDELIS);
    app(TreasuryOrders::class)->read($second->id, Programme::FIDELIS);

    $issue = FinancialInstrument::query()->where('isin', 'ROQEJCC1VXP7')->firstOrFail();

    expect(FinancialInstrument::query()->count())->toBe(7)
        ->and($issue->rate)->toBe('7.60')
        ->and($issue->terms_document_id)->toBe($first->id)
        ->and(TreasuryOrder::query()->where('source_document_id', $first->id)->firstOrFail()->skipped_issues)->toBe([])
        ->and(TreasuryOrder::query()->where('source_document_id', $second->id)->firstOrFail()->skipped_issues)->toHaveCount(7)
        ->and(app(LawFlags::class)->for([$first->id, $second->id]))->toBe([$second->id => [['code' => 'bonds_skipped', 'count' => 7]]]);
});

it('records an order with no issue as read, and writes nothing for it', function (): void {
    $order = storedOrder('tezaur-prospectus', 'ORDIN … Programul FIDELIS');

    app(TreasuryOrders::class)->read($order->id, Programme::FIDELIS);

    expect(FinancialInstrument::query()->count())->toBe(0)
        ->and(TreasuryOrder::query()->value('issues_count'))->toBe(0);
});

it('reads again an order read as having no issue, and writes the issues it finds now', function (): void {
    // As a reading that did not know this layout recorded it.
    $order = storedOrder('fidelis-terms', 'ORDIN … Programul FIDELIS');
    TreasuryOrder::query()->create(['source_document_id' => $order->id, 'programme' => Programme::FIDELIS, 'issues_count' => 0, 'read_at' => now()->subDay()]);

    app(TreasuryOrders::class)->read($order->id, Programme::FIDELIS);

    expect(TreasuryOrder::query()->sole()->issues_count)->toBe(7)
        ->and(FinancialInstrument::query()->count())->toBe(7);
});

it('reads again an order whose law was read since under a newer parser, and writes what it says now', function (): void {
    Queue::fake();
    $order = storedOrder('fidelis-terms', 'ORDIN … Programul FIDELIS');
    app(TreasuryOrders::class)->read($order->id, Programme::FIDELIS);
    $coupon = static fn (): InstrumentPayout => InstrumentPayout::query()->where('kind', PayoutKind::COUPON)->orderBy('payment_date')->firstOrFail();
    // As an older reading of the law left it: no periods.
    InstrumentPayout::query()->update(['period_start' => null, 'period_end' => null]);
    SourceDocumentVersion::query()->where('source_document_id', $order->id)->update(['parser_version' => '99.0.0']);

    expect(app(UnreadOrders::class)->queue())->toContain('orders: 1 ');

    app(TreasuryOrders::class)->read($order->id, Programme::FIDELIS);

    expect(TreasuryOrder::query()->sole()->parser_version)->toBe('99.0.0')
        ->and($coupon()->period_start?->format('Y-m-d'))->toBe('2026-10-14')
        ->and(app(UnreadOrders::class)->queue())->toContain('orders: 0 ');
});

it('reads an order from its provisions as stored, its page gone from the object store', function (): void {
    $order = storedOrder('tezaur-prospectus', 'ORDIN … în cadrul Programului Tezaur');
    app(RawSourceObjectStore::class)->forget((string) SourceDocumentVersion::query()->where('source_document_id', $order->id)->value('raw_object_path'));

    app(TreasuryOrders::class)->read($order->id, Programme::TEZAUR);

    expect(FinancialInstrument::query()->pluck('issue_code')->sort()->values()->all())->toBe(['0228', '0229', '0230']);
});

it('writes nothing of an order whose terms do not read', function (): void {
    $order = storedOrder('fidelis-terms', 'ORDIN … Programul FIDELIS', ['ROD7VZT6IIK7' => 'ROD7VZT6IIK8']);

    expect(fn () => app(TreasuryOrders::class)->read($order->id, Programme::FIDELIS))->toThrow(ParseFailure::class, 'check digit')
        ->and(FinancialInstrument::query()->count())->toBe(0)
        ->and(TreasuryOrder::query()->count())->toBe(0);
});

it('queues the reading of a programme’s order once it is read as a law, and of nothing else', function (): void {
    Queue::fake();

    LawConsolidationsRead::dispatch('doc-1', 'ORDIN', 'ORDIN nr. 1.324 … în cadrul Programului Tezaur, în perioada octombrie-noiembrie 2026');
    LawConsolidationsRead::dispatch('doc-2', 'LEGE', 'LEGE … în cadrul Programului Tezaur');
    LawConsolidationsRead::dispatch('doc-3', 'ORDIN', 'ORDIN privind altceva');

    Queue::assertPushed(ReadTreasuryOrder::class, 1);
    Queue::assertPushed(ReadTreasuryOrder::class, fn (ReadTreasuryOrder $job): bool => $job->sourceDocumentId === 'doc-1' && $job->programme === Programme::TEZAUR);
});
