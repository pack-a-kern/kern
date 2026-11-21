<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kern\Investments\Config\BvbSource;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\IssuerProfile;
use Kern\Investments\Parsing\Bvb\FinancialEvent;
use Kern\Investments\Parsing\Bvb\IssuerPageParser;
use Kern\Investments\Parsing\Bvb\Meeting;
use Kern\Investments\Parsing\Bvb\Shareholder;
use Kern\Legislation\Fetching\AllowlistedHttpFetcher;
use Kern\Legislation\Services\SourceIngestionService;
use Throwable;

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
        /** The plain allow-listed HTTP fetcher (not the browser-worker-routed {@see PageFetcher}): the
         * logo is a static image on an already-allowed host, fetched directly. */
        private AllowlistedHttpFetcher $http,
    ) {
    }

    /** Media types BVB serves logos as, each to the file extension the stored image is kept under. */
    private const array LOGO_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    /** A logo larger than this is treated as not-an-icon and ignored — a guard, well above any real logo. */
    private const int LOGO_MAX_BYTES = 2_097_152;

    public function refresh(string $key): IssuerProfile
    {
        // Only a share or bond listed on BVB: a made-up symbol is not a page to fetch and keep.
        FinancialInstrument::query()->listedOnBvb($key)->firstOrFail();

        $stored = IssuerProfile::query()->where('issuer_key', $key)->first();

        if ($stored !== null && $stored->read_at->greaterThan(now()->subHours($this->config->api->profileRefreshHours))) {
            return $stored;
        }

        $pageUrl = $this->config->bvb->instrumentUrl($key);
        $page = $this->ingestion->ingest(BvbSource::CODE, $pageUrl, 'BVB_INSTRUMENT', "BVB instrument {$key}");
        $issuer = IssuerPageParser::parse($page->rawBytes);
        $logo = $this->logo($key, $pageUrl, $issuer->logoUrl, $stored);

        return IssuerProfile::query()->updateOrCreate(['issuer_key' => $key], [
            ...$logo,
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

    /**
     * The company logo the page points at, fetched and kept on the `issuer_logos` disk, as the columns to
     * save with the profile. Best-effort: anything that goes wrong — no logo on the page, a host the
     * source does not allow, a non-image or oversized response, a network failure — keeps whatever logo
     * the profile already had rather than breaking the refresh or erasing a good logo. When the bytes are
     * the same as last time (by hash) the stored file is left as it is.
     *
     * @return array{logo_path: string|null, logo_mime: string|null, logo_etag: string|null}
     */
    private function logo(string $key, string $pageUrl, ?string $src, ?IssuerProfile $stored): array
    {
        $keep = ['logo_path' => $stored?->logo_path, 'logo_mime' => $stored?->logo_mime, 'logo_etag' => $stored?->logo_etag];

        if ($src === null) {
            return $keep;
        }

        try {
            $fetched = $this->http->fetch(BvbSource::CODE, self::resolveUrl($pageUrl, $src));
            $type = strtolower(trim(explode(';', $fetched->contentType ?? '')[0]));
            $extension = self::LOGO_TYPES[$type] ?? null;

            if ($fetched->statusCode !== 200 || $extension === null || $fetched->body === '' || strlen($fetched->body) > self::LOGO_MAX_BYTES) {
                return $keep;
            }

            $etag = hash('sha256', $fetched->body);

            // Unchanged since last time, and the file is still on disk: nothing to rewrite.
            if ($stored?->logo_etag === $etag && $stored->logo_path !== null && Storage::disk('issuer_logos')->exists($stored->logo_path)) {
                return $keep;
            }

            $path = "{$key}.{$extension}";
            Storage::disk('issuer_logos')->put($path, $fetched->body);

            return ['logo_path' => $path, 'logo_mime' => $type, 'logo_etag' => $etag];
        } catch (Throwable $e) {
            Log::warning('Issuer logo not fetched', ['issuer' => $key, 'error' => $e->getMessage()]);

            return $keep;
        }
    }

    /** An image address the page gives — absolute, root-relative, or relative to the page — as an absolute URL. */
    private static function resolveUrl(string $pageUrl, string $src): string
    {
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }

        $scheme = parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($pageUrl, PHP_URL_HOST) ?: '';
        $origin = "{$scheme}://{$host}";

        if (str_starts_with($src, '/')) {
            return $origin . $src;
        }

        $directory = rtrim(str_replace('\\', '/', dirname(parse_url($pageUrl, PHP_URL_PATH) ?: '/')), '/');

        return "{$origin}{$directory}/{$src}";
    }
}
