<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;
use DateTimeZone;
use Dom\Element;
use Kern\Investments\Parsing\Html\HtmlPage;
use Kern\Investments\Parsing\Html\HtmlTable;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Parsing\Values;
use Kern\Platform\Money\CurrencyCode;

/**
 * BVB's three lists — shares, bonds, dividends — each one table found by its column headers, every
 * value read strictly. A row that does not read fails the whole list: half a list is not written.
 *
 * The headers are the page's own words: they are what the page is recognised by, so a renamed column
 * is a list that no longer reads, and says so.
 */
final readonly class BvbListings
{
    private const string SYMBOL = 'Simbol / ISIN';

    private const string COMPANY = 'Societate';

    private const string CHANGE = 'Var. (%)';

    private const string TIME = 'Data';

    public function __construct(
        private DateTimeZone $zone,
    ) {
    }

    /** @return list<ListedShare> */
    public function shares(string $html): array
    {
        $price = 'Pret (RON)';

        return $this->read($html, 'BVB shares', [self::SYMBOL, self::COMPANY, $price, self::CHANGE, self::TIME], function (array $row, string $where) use ($price): ListedShare {
            [$symbol, $isin] = $this->identity($row[self::SYMBOL], $where);

            return new ListedShare(
                symbol: $symbol,
                isin: $isin,
                company: Values::name(HtmlPage::text($row[self::COMPANY]), "{$where}, " . self::COMPANY),
                price: Values::optionalDecimal(HtmlPage::text($row[$price]), "{$where}, {$price}"),
                changePercent: Values::optionalDecimal(HtmlPage::text($row[self::CHANGE]), "{$where}, " . self::CHANGE),
                pricedAt: $this->time($row[self::TIME], $where),
            );
        });
    }

    /** @return list<ListedBond> */
    public function bonds(string $html): array
    {
        [$price, $coupon, $currency, $maturity, $type] = ['Pret (%)', 'Cupon curent (%)', 'Moneda de emisiune', 'Data maturitatii', 'Tip'];

        return $this->read($html, 'BVB bonds', [self::SYMBOL, self::COMPANY, $price, self::CHANGE, self::TIME, $coupon, $currency, $maturity, $type], function (array $row, string $where) use ($price, $coupon, $currency, $maturity, $type): ListedBond {
            [$symbol, $isin] = $this->identity($row[self::SYMBOL], $where);
            $code = HtmlPage::text($row[$currency]);

            return new ListedBond(
                symbol: $symbol,
                isin: $isin,
                issuer: Values::name(HtmlPage::text($row[self::COMPANY]), "{$where}, " . self::COMPANY),
                pricePercent: Values::optionalDecimal(HtmlPage::text($row[$price]), "{$where}, {$price}"),
                changePercent: Values::optionalDecimal(HtmlPage::text($row[self::CHANGE]), "{$where}, " . self::CHANGE),
                pricedAt: $this->time($row[self::TIME], $where),
                couponPercent: Values::optionalDecimal(HtmlPage::text($row[$coupon]), "{$where}, {$coupon}"),
                currency: CurrencyCode::isValid($code) ? $code : throw ParseFailure::at("{$where}, {$currency}", 'not a currency'),
                maturity: Values::date(HtmlPage::text($row[$maturity]), "{$where}, {$maturity}"),
                type: BvbBondType::tryFrom(HtmlPage::text($row[$type])) ?? throw ParseFailure::at("{$where}, {$type}", 'a bond type this list does not know'),
            );
        });
    }

    /** @return list<AnnouncedDividend> */
    public function dividends(string $html): array
    {
        [$amount, $ex, $paid, $year, $record] = ['Dividend', 'Data ex dividend', 'Data platii', 'An', 'Data Inregistrare'];

        return $this->read($html, 'BVB dividends', [self::SYMBOL, self::COMPANY, $amount, $ex, $paid, $year, $record], function (array $row, string $where) use ($amount, $ex, $paid, $year, $record): AnnouncedDividend {
            [$symbol, $isin] = $this->identity($row[self::SYMBOL], $where);
            $financialYear = HtmlPage::text($row[$year]);

            return new AnnouncedDividend(
                symbol: $symbol,
                isin: $isin,
                company: Values::name(HtmlPage::text($row[self::COMPANY]), "{$where}, " . self::COMPANY),
                amountPerShare: Values::decimal(HtmlPage::text($row[$amount]), "{$where}, {$amount}"),
                exDate: Values::date(HtmlPage::text($row[$ex]), "{$where}, {$ex}"),
                recordDate: Values::date(HtmlPage::text($row[$record]), "{$where}, {$record}"),
                paymentDate: Values::date(HtmlPage::text($row[$paid]), "{$where}, {$paid}"),
                financialYear: preg_match('/\A(19|20)\d{2}\z/', $financialYear) === 1 ? (int) $financialYear : throw ParseFailure::at("{$where}, {$year}", 'not a year'),
            );
        });
    }

    /**
     * @template T
     *
     * @param list<string> $headers
     * @param callable(array<string, Element>, string): T $row
     *
     * @return list<T>
     */
    private function read(string $html, string $source, array $headers, callable $row): array
    {
        $rows = HtmlTable::withHeaders(HtmlPage::load($html), $headers, $source);

        if ($rows === []) {
            throw ParseFailure::at($source, 'the list is empty');
        }

        return array_map(static fn (array $cells, int $at): mixed => $row($cells, "{$source}, row " . ($at + 1)), $rows, array_keys($rows));
    }

    /**
     * The symbol and the ISIN, which BVB prints in one cell: the symbol as the link to its page, the ISIN under it.
     *
     * @return array{string, string}
     */
    private function identity(Element $cell, string $where): array
    {
        return [
            Values::symbol(HtmlPage::text($cell->querySelector('a')), "{$where}, symbol"),
            Values::isin(HtmlPage::text($cell->querySelector('p')), "{$where}, ISIN"),
        ];
    }

    private function time(Element $cell, string $where): ?DateTimeImmutable
    {
        $text = HtmlPage::text($cell);

        return $text === '' ? null : Values::dateTime($text, $this->zone, "{$where}, " . self::TIME);
    }
}
