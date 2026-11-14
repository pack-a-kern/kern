<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Investments\Config\BvbSource;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\PayoutSource;
use Kern\Investments\Enums\PayoutStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Parsing\Bvb\AnnouncedDividend;
use Kern\Investments\Parsing\Bvb\BvbBondType;
use Kern\Investments\Parsing\Bvb\BvbListings;
use Kern\Investments\Parsing\Bvb\ListedBond;
use Kern\Investments\Parsing\Bvb\ListedShare;
use Kern\Legislation\Services\SourceIngestionService;

/**
 * BVB's day (ADR 0025 §5): its three lists read, kept as evidence and parsed whole before anything is
 * written — a list that does not read writes nothing.
 *
 * - A share, a corporate or a municipal bond is written by its ISIN; a government bond only when an order
 *   already made it — one listed with no order behind it is reported, never invented.
 * - A price is stored under the day BVB prints beside it, so a day the exchange is closed adds nothing.
 * - A dividend is written for a share already known; one for any other is counted and skipped.
 * - Nothing is deleted. A share or a bond no longer listed is delisted; one past its maturity matured; an
 *   issue whose day has come active; a payment whose day has passed paid — an order's coupons too, which
 *   no list brings again.
 */
final readonly class BvbImport
{
    private const array KINDS = [
        'Titluri de stat' => InstrumentKind::GOVERNMENT_BOND,
        'Obligatiuni corporative' => InstrumentKind::CORPORATE_BOND,
        'Obligatiuni municipale' => InstrumentKind::MUNICIPAL_BOND,
    ];

    public function __construct(
        private InvestmentsConfig $config,
        private SourceIngestionService $ingestion,
    ) {
    }

    /** @return list<string> a line per part, for the run's output */
    public function run(): array
    {
        $bvb = $this->config->bvb;
        $listings = new BvbListings($bvb->timezone);
        $pages = [
            'shares' => $this->ingestion->ingest(BvbSource::CODE, $bvb->sharesUrl, 'BVB_SHARES', 'BVB shares'),
            'bonds' => $this->ingestion->ingest(BvbSource::CODE, $bvb->bondsUrl, 'BVB_BONDS', 'BVB bonds'),
            'dividends' => $this->ingestion->ingest(BvbSource::CODE, $bvb->dividendsUrl, 'BVB_DIVIDENDS', 'BVB dividends'),
        ];
        // Parsed whole, all three, before a row is written.
        $shares = $listings->shares($pages['shares']->rawBytes);
        $bonds = $listings->bonds($pages['bonds']->rawBytes);
        $dividends = $listings->dividends($pages['dividends']->rawBytes);

        return DB::transaction(function () use ($shares, $bonds, $dividends, $pages): array {
            $now = now();
            $today = Carbon::today('UTC')->toDateTimeImmutable();

            $this->writeShares($shares, $now);
            $orphans = $this->writeBonds($bonds, $now);
            $listed = [...array_map(static fn (ListedShare $s): string => $s->isin, $shares), ...array_map(static fn (ListedBond $b): string => $b->isin, $bonds)];
            $ids = $this->idsByIsin($listed);

            $prices = $this->writePrices([...$shares, ...$bonds], $ids, $pages['shares']->sourceDocumentVersionId, $pages['bonds']->sourceDocumentVersionId, $now);
            [$paid, $unknown] = $this->writeDividends($dividends, $this->idsByIsin(array_map(static fn (AnnouncedDividend $d): string => $d->isin, $dividends)), $today, $now);
            [$delisted, $matured, $issued, $paidOut] = $this->updateStatuses($listed, $today);
            $unlisted = FinancialInstrument::query()->where('programme', Programme::FIDELIS)->where('status', InstrumentStatus::ACTIVE)->whereNotIn('isin', $listed)->count();

            return [
                sprintf('BVB: %d shares, %d bonds, %d prices, %d dividends written (%d for a share not known, skipped)', count($shares), count($bonds) - $orphans, $prices, $paid, $unknown),
                sprintf('BVB: %d listed government bonds with no order behind them, not written; %d active Fidelis issues not listed', $orphans, $unlisted),
                sprintf('BVB: %d delisted, %d matured, %d issued, %d payments paid since the last run', $delisted, $matured, $issued, $paidOut),
            ];
        });
    }

    /** @param list<ListedShare> $shares */
    private function writeShares(array $shares, Carbon $now): void
    {
        foreach (array_chunk($shares, $this->config->writeChunk) as $chunk) {
            FinancialInstrument::query()->upsert(array_map(static fn (ListedShare $share): array => [
                'id' => (string) Str::uuid7(),
                'kind' => InstrumentKind::SHARE->value,
                'isin' => $share->isin,
                'symbol' => $share->symbol,
                'name' => $share->company,
                'issuer_name' => $share->company,
                'currency' => 'RON',
                'market' => Market::BVB->value,
                'status' => InstrumentStatus::ACTIVE->value,
                'listing_read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk), ['isin'], ['symbol', 'name', 'issuer_name', 'status', 'listing_read_at', 'updated_at']);
        }
    }

    /**
     * @param list<ListedBond> $bonds
     *
     * @return int how many government bonds were listed with no order behind them
     */
    private function writeBonds(array $bonds, Carbon $now): int
    {
        $government = array_filter($bonds, static fn (ListedBond $b): bool => $b->type === BvbBondType::GOVERNMENT);
        $known = FinancialInstrument::query()->whereIn('isin', array_map(static fn (ListedBond $b): string => $b->isin, $government))->pluck('isin')->all();

        // A government bond's terms are its order's: BVB only says it is listed.
        foreach (array_chunk($known, $this->config->writeChunk) as $chunk) {
            FinancialInstrument::query()->whereIn('isin', $chunk)->update(['listing_read_at' => $now]);
        }

        $others = array_filter($bonds, static fn (ListedBond $b): bool => $b->type !== BvbBondType::GOVERNMENT);

        foreach (array_chunk($others, $this->config->writeChunk) as $chunk) {
            FinancialInstrument::query()->upsert(array_map(static fn (ListedBond $bond): array => [
                'id' => (string) Str::uuid7(),
                'kind' => self::KINDS[$bond->type->value]->value,
                'isin' => $bond->isin,
                'symbol' => $bond->symbol,
                'name' => "{$bond->issuer} {$bond->symbol}",
                'issuer_name' => $bond->issuer,
                'currency' => $bond->currency,
                'rate' => $bond->couponPercent,
                'maturity_date' => $bond->maturity->format('Y-m-d'),
                'market' => Market::BVB->value,
                'status' => InstrumentStatus::ACTIVE->value,
                'listing_read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk), ['isin'], ['symbol', 'name', 'issuer_name', 'rate', 'maturity_date', 'status', 'listing_read_at', 'updated_at']);
        }

        return count($government) - count($known);
    }

    /**
     * @param list<ListedShare|ListedBond> $listed
     * @param array<string, string> $ids instrument id by ISIN
     */
    private function writePrices(array $listed, array $ids, string $sharesPage, string $bondsPage, Carbon $now): int
    {
        $rows = [];

        foreach ($listed as $item) {
            $price = $item instanceof ListedShare ? $item->price : $item->pricePercent;

            if ($price === null || $item->pricedAt === null || ! isset($ids[$item->isin])) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'instrument_id' => $ids[$item->isin],
                // The day BVB's own clock shows beside the price, whatever the server's says.
                'trading_date' => $item->pricedAt->setTimezone($this->config->bvb->timezone)->format('Y-m-d'),
                'price' => $price,
                'change_percent' => $item->changePercent,
                'priced_at' => $item->pricedAt,
                'evidence_id' => $item instanceof ListedShare ? $sharesPage : $bondsPage,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, $this->config->writeChunk) as $chunk) {
            DB::table('ext_instrument_prices')->upsert($chunk, ['instrument_id', 'trading_date'], ['price', 'change_percent', 'priced_at', 'evidence_id', 'updated_at']);
        }

        return count($rows);
    }

    /**
     * @param list<AnnouncedDividend> $dividends
     * @param array<string, string> $ids instrument id by ISIN
     *
     * @return array{int, int} written, and skipped for a share not known
     */
    private function writeDividends(array $dividends, array $ids, DateTimeImmutable $today, Carbon $now): array
    {
        $rows = [];

        foreach ($dividends as $dividend) {
            if (! isset($ids[$dividend->isin])) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'instrument_id' => $ids[$dividend->isin],
                'kind' => PayoutKind::DIVIDEND->value,
                'ex_date' => $dividend->exDate->format('Y-m-d'),
                'record_date' => $dividend->recordDate->format('Y-m-d'),
                'payment_date' => $dividend->paymentDate->format('Y-m-d'),
                'amount_per_unit' => $dividend->amountPerShare,
                'financial_year' => $dividend->financialYear,
                'status' => ($dividend->paymentDate < $today ? PayoutStatus::PAID : PayoutStatus::ANNOUNCED)->value,
                'source' => PayoutSource::BVB->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, $this->config->writeChunk) as $chunk) {
            DB::table('ext_instrument_payouts')->upsert($chunk, ['instrument_id', 'kind', 'payment_date'], ['ex_date', 'record_date', 'amount_per_unit', 'financial_year', 'status', 'updated_at']);
        }

        return [count($rows), count($dividends) - count($rows)];
    }

    /**
     * @param list<string> $listed every ISIN BVB lists today
     *
     * @return array{int, int, int, int} delisted, matured, issued, payments paid
     */
    private function updateStatuses(array $listed, DateTimeImmutable $today): array
    {
        $day = $today->format('Y-m-d');
        $matured = FinancialInstrument::query()->whereIn('status', [InstrumentStatus::ACTIVE, InstrumentStatus::ON_OFFER])->where('maturity_date', '<', $day)->update(['status' => InstrumentStatus::MATURED]);
        $issued = FinancialInstrument::query()->where('status', InstrumentStatus::ON_OFFER)->where('issue_date', '<=', $day)->update(['status' => InstrumentStatus::ACTIVE]);
        // A government bond is not listed until its first trading day, nor once it matures: only what BVB
        // alone brought is delisted by BVB.
        $delisted = FinancialInstrument::query()
            ->where('market', Market::BVB)
            ->whereIn('kind', [InstrumentKind::SHARE, InstrumentKind::CORPORATE_BOND, InstrumentKind::MUNICIPAL_BOND])
            ->where('status', InstrumentStatus::ACTIVE)
            ->whereNotIn('isin', $listed)
            ->update(['status' => InstrumentStatus::DELISTED]);

        $paid = InstrumentPayout::query()->where('status', PayoutStatus::ANNOUNCED)->where('payment_date', '<', $day)->update(['status' => PayoutStatus::PAID]);

        return [$delisted, $matured, $issued, $paid];
    }

    /**
     * @param list<string> $isins
     *
     * @return array<string, string>
     */
    private function idsByIsin(array $isins): array
    {
        $ids = [];

        foreach (array_chunk(array_values(array_unique($isins)), $this->config->writeChunk) as $chunk) {
            foreach (FinancialInstrument::query()->whereIn('isin', $chunk)->get(['id', 'isin']) as $instrument) {
                $ids[(string) $instrument->isin] = $instrument->id;
            }
        }

        return $ids;
    }
}
