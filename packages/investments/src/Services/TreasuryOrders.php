<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\PayoutSource;
use Kern\Investments\Enums\PayoutStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Events\IssueOnOffer;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\TreasuryOrder;
use Kern\Investments\Parsing\Orders\OrderIssue;
use Kern\Investments\Parsing\Orders\OrderPayout;
use Kern\Investments\Parsing\Orders\TreasuryOrderParser;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;
use Kern\Legislation\Storage\RawSourceObjectStore;

/**
 * A Ministry of Finance order, read for the issues it launches and written as instruments and their
 * payouts (ADR 0025 §5). Read from the page `kern/legislation` kept of it, never fetched again; an order
 * already read is not read twice, one with no issue is recorded as read all the same.
 *
 * An instrument is found by its ISIN (Fidelis) or its programme and code (Tezaur), so an order read again
 * changes nothing, and nothing an order wrote is ever deleted by another. All of it in one transaction: an
 * order is read whole or not at all.
 */
final readonly class TreasuryOrders
{
    public function __construct(
        private RawSourceObjectStore $pages,
        private LegislatieJustRoHtmlParser $parser,
    ) {
    }

    public function read(string $sourceDocumentId, Programme $programme): void
    {
        if (TreasuryOrder::query()->where('source_document_id', $sourceDocumentId)->exists()) {
            return;
        }

        $order = SourceDocument::query()->findOrFail($sourceDocumentId);
        $version = SourceDocumentVersion::query()->where('source_document_id', $order->id)->latest('retrieved_at')->firstOrFail();
        $parsed = $this->parser->parse($this->pages->retrieve($version->raw_object_path));
        $issues = $programme === Programme::FIDELIS ? TreasuryOrderParser::fidelis($parsed) : TreasuryOrderParser::tezaur($parsed);
        $issuer = is_string($order->issuer) && $order->issuer !== '' ? $order->issuer : throw ParseFailure::at("order {$order->id}", 'names no issuer');

        DB::transaction(function () use ($order, $programme, $issues, $issuer): void {
            foreach ($issues as $issue) {
                $this->write($issue, $order->id, $issuer);
            }

            TreasuryOrder::query()->create([
                'source_document_id' => $order->id,
                'programme' => $programme,
                'issues_count' => count($issues),
                'read_at' => now(),
            ]);
        });
    }

    private function write(OrderIssue $issue, string $orderId, string $issuer): void
    {
        // The application's clock, as the scheduler's: an issue is on offer until the day it is issued.
        $today = Carbon::today('UTC')->toDateTimeImmutable();
        $code = $issue->symbol ?? $issue->issueCode;
        $instrument = FinancialInstrument::query()->firstOrNew(
            $issue->isin !== null ? ['isin' => $issue->isin] : ['programme' => $issue->programme, 'issue_code' => $issue->issueCode],
        );
        $isNew = ! $instrument->exists;

        $instrument->fill([
            'kind' => InstrumentKind::GOVERNMENT_BOND,
            'programme' => $issue->programme,
            'symbol' => $issue->symbol,
            'issue_code' => $issue->issueCode,
            'name' => "{$issue->programme->value} {$code}",
            'issuer_name' => $issuer,
            'currency' => $issue->currency,
            'face_value' => $issue->faceValue,
            'rate' => $issue->ratePercent,
            'issue_date' => $issue->issueDate,
            'maturity_date' => $issue->maturityDate,
            // Fidelis is listed on BVB from its first trading day; Tezaur is never listed.
            'market' => $issue->programme === Programme::FIDELIS ? Market::BVB : null,
            'status' => match (true) {
                $issue->issueDate > $today => InstrumentStatus::ON_OFFER,
                $issue->maturityDate < $today => InstrumentStatus::MATURED,
                default => InstrumentStatus::ACTIVE,
            },
            'terms_document_id' => $orderId,
        ])->save();

        InstrumentPayout::query()->upsert(
            array_map(fn (OrderPayout $payout): array => $this->payout($payout, $issue, $instrument->id, $today), $issue->payouts),
            ['instrument_id', 'kind', 'payment_date'],
            ['period_start', 'period_end', 'record_date', 'amount_per_unit', 'rate', 'status', 'source', 'updated_at'],
        );

        if ($isNew && $instrument->status === InstrumentStatus::ON_OFFER) {
            IssueOnOffer::dispatch($instrument->id);
        }
    }

    /** @return array<string, mixed> a row of `ext_instrument_payouts`, as the upsert writes it */
    private function payout(OrderPayout $payout, OrderIssue $issue, string $instrumentId, DateTimeImmutable $today): array
    {
        $coupon = $payout->kind === PayoutKind::COUPON;

        return [
            'id' => (string) Str::uuid7(),
            'instrument_id' => $instrumentId,
            'kind' => $payout->kind->value,
            'period_start' => $payout->periodStart?->format('Y-m-d'),
            'period_end' => $payout->periodEnd?->format('Y-m-d'),
            'record_date' => $payout->recordDate?->format('Y-m-d'),
            'payment_date' => $payout->paymentDate->format('Y-m-d'),
            // A Fidelis coupon is the face value times the rate, the order's period a year; a Tezaur coupon
            // counts its days (ACT/ACT), so only its rate is known until a holding computes it.
            'amount_per_unit' => match (true) {
                ! $coupon => $issue->faceValue,
                $issue->programme === Programme::FIDELIS => bcdiv(bcmul($issue->faceValue, $issue->ratePercent, 6), '100', 6),
                default => null,
            },
            'rate' => $coupon ? $issue->ratePercent : null,
            'status' => ($payout->paymentDate < $today ? PayoutStatus::PAID : PayoutStatus::ANNOUNCED)->value,
            'source' => PayoutSource::ORDER->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
