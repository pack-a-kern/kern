<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use Kern\Investments\Config\BvbSource;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\IssuerProfile;
use Kern\Investments\Parsing\Bvb\FinancialEvent;
use Kern\Investments\Parsing\Bvb\IssuerPageParser;
use Kern\Investments\Parsing\Bvb\Meeting;
use Kern\Investments\Parsing\Bvb\Shareholder;
use Kern\Legislation\Services\SourceIngestionService;

/**
 * A listed instrument's profile — a company's meetings and who holds it, a bond's yield and issue, and
 * what both show of their price — read from its BVB page only when a person asks (ADR 0025 §5), the page kept as evidence. Asked again while the stored one is younger than its
 * refresh age, the stored one is the answer and BVB is not asked.
 */
final readonly class IssuerProfiles
{
    public function __construct(
        private InvestmentsConfig $config,
        private SourceIngestionService $ingestion,
    ) {
    }

    public function refresh(string $key): IssuerProfile
    {
        // Only a share or bond listed on BVB: a made-up symbol is not a page to fetch and keep.
        FinancialInstrument::query()->listedOnBvb($key)->firstOrFail();

        $stored = IssuerProfile::query()->where('issuer_key', $key)->first();

        if ($stored !== null && $stored->read_at->greaterThan(now()->subHours($this->config->api->profileRefreshHours))) {
            return $stored;
        }

        $page = $this->ingestion->ingest(BvbSource::CODE, $this->config->bvb->instrumentUrl($key), 'BVB_INSTRUMENT', "BVB instrument {$key}");
        $issuer = IssuerPageParser::parse($page->rawBytes);

        return IssuerProfile::query()->updateOrCreate(['issuer_key' => $key], [
            'meetings' => array_map(static fn (Meeting $m): array => ['date' => $m->date->format('Y-m-d'), 'kind' => $m->kind], $issuer->meetings),
            'shareholders' => array_map(static fn (Shareholder $s): array => ['holder' => $s->holder, 'shares' => $s->shares, 'percent' => $s->percent], $issuer->shareholders),
            'shareholders_as_of' => $issuer->shareholdersAsOf?->format('Y-m-d'),
            'financial_calendar' => array_map(static fn (FinancialEvent $e): array => ['date' => $e->date->format('Y-m-d'), 'what' => $e->what], $issuer->financialCalendar),
            'market_cap' => $issuer->figures->marketCap,
            'price_earnings' => $issuer->figures->priceEarnings,
            'price_book' => $issuer->figures->priceBook,
            'earnings_per_share' => $issuer->figures->earningsPerShare,
            'dividend_yield' => $issuer->figures->dividendYield,
            'last_dividend' => $issuer->figures->lastDividend,
            'last_dividend_year' => $issuer->figures->lastDividendYear,
            'week52_high' => $issuer->figures->week52High,
            'week52_low' => $issuer->figures->week52Low,
            'shares_total' => $issuer->figures->sharesTotal,
            'face_value' => $issuer->figures->faceValue,
            'share_capital' => $issuer->figures->shareCapital,
            'trading_since' => $issuer->figures->tradingSince?->format('Y-m-d'),
            'segment' => $issuer->figures->segment,
            'category' => $issuer->figures->category,
            'bid' => $issuer->figures->bid,
            'ask' => $issuer->figures->ask,
            'coupon_rate' => $issuer->figures->couponRate,
            'yield_to_maturity' => $issuer->figures->yieldToMaturity,
            'units_issued' => $issuer->figures->unitsIssued,
            'issue_value' => $issuer->figures->issueValue,
            'read_at' => now(),
            'evidence_id' => $page->sourceDocumentVersionId,
        ]);
    }
}
