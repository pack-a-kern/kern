<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
use Kern\Legislation\Provisions\StoredProvisions;

/**
 * A Ministry of Finance order, read for the issues it launches and written as instruments and their
 * payouts (ADR 0025 §5). Read from its provisions as `kern/legislation` stored them, never fetched again —
 * a law it reads again with a better parser reads here as it now stands; an order read for its issues is
 * not read twice. One with no issue is recorded as read all the same, and read again
 * when asked: a layout this reading did not know then is one it may know now.
 *
 * An instrument is found by its ISIN (Fidelis) or its programme and code (Tezaur). One another order
 * already wrote is left as that order wrote it: skipped, counted on this order's record, said in the log —
 * an order is never let to rewrite another's bond, however its terms read. All of it in one transaction:
 * an order is read whole or not at all.
 */
final readonly class TreasuryOrders
{
    public function __construct(
        private StoredProvisions $provisions,
    ) {
    }

    public function read(string $sourceDocumentId, Programme $programme): void
    {
        if (TreasuryOrder::query()->where('source_document_id', $sourceDocumentId)->where('issues_count', '>', 0)->exists()) {
            return;
        }

        $order = SourceDocument::query()->findOrFail($sourceDocumentId);
        $provisions = $this->provisions->latest($order->id);
        $issues = $programme === Programme::FIDELIS ? TreasuryOrderParser::fidelis($provisions) : TreasuryOrderParser::tezaur($provisions);
        $issuer = is_string($order->issuer) && $order->issuer !== '' ? $order->issuer : throw ParseFailure::at("order {$order->id}", 'names no issuer');

        DB::transaction(function () use ($order, $programme, $issues, $issuer): void {
            $skipped = [];

            foreach ($issues as $issue) {
                if (! $this->write($issue, $order->id, $issuer)) {
                    $skipped[] = $issue->isin ?? $issue->issueCode;
                }
            }

            if ($skipped !== []) {
                Log::warning('An order lists bonds another order already wrote; they were left as they are.', [
                    'order_id' => $order->id,
                    'skipped' => $skipped,
                ]);
            }

            TreasuryOrder::query()->updateOrCreate(['source_document_id' => $order->id], [
                'programme' => $programme,
                'issues_count' => count($issues),
                'skipped_issues' => $skipped,
                'read_at' => now(),
            ]);
        });
    }

    /** @return bool whether it was written: false for an issue another order already wrote, left as it is */
    private function write(OrderIssue $issue, string $orderId, string $issuer): bool
    {
        // The application's clock, as the scheduler's: an issue is on offer until the day it is issued.
        $today = Carbon::today('UTC')->toDateTimeImmutable();
        $code = $issue->symbol ?? $issue->issueCode;
        $instrument = FinancialInstrument::query()->firstOrNew(
            $issue->isin !== null ? ['isin' => $issue->isin] : ['programme' => $issue->programme, 'issue_code' => $issue->issueCode],
        );
        $isNew = ! $instrument->exists;

        if (! $isNew && $instrument->terms_document_id !== $orderId) {
            return false;
        }

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

        return true;
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
