<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use Dom\Element;
use Dom\HTMLDocument;
use Kern\Investments\Parsing\Html\HtmlPage;
use Kern\Investments\Parsing\Html\HtmlTable;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Parsing\Values;

/**
 * An instrument's BVB page — a share's or a bond's — read for its issuer: the "Calendar AGA" and "Calendar
 * Financiar" sections — no column headers, so found by their headings — the "Structura actionariat" table,
 * found by its columns, and the figures its label/value tables show ("Preturi", "Indicatori bursieri",
 * "Informatii emisiune", and the page head's segment and category). A page with no meetings, shareholders
 * or issue is not an instrument page and fails; a section with no rows is an issuer with nothing listed
 * there; a figure the page leaves out, or shows as a dash, is none, and one it shows that is not a number
 * fails.
 */
final class IssuerPageParser
{
    private const string SOURCE = 'BVB instrument page';

    private const string MEETINGS = 'Calendar AGA';

    private const string STRUCTURE = 'Structura actionariat';

    private const string FINANCIAL = 'Calendar Financiar';

    private const string PRICES = 'Preturi';

    private const string INDICATORS = 'Indicatori bursieri';

    private const string ISSUE = 'Informatii emisiune';

    private const array SHAREHOLDER_COLUMNS = ['Actionar', 'Actiuni', 'Procent'];

    /** How the page shows a figure it has none of — a bond's yield with no price to compute it from. */
    private const string NONE = '-';

    /** The best bid and ask in one cell: "97,1044 / 99,9799". */
    private const string BID_ASK = '/';

    /** The structure's last line, which adds up the lines above it. */
    private const string TOTAL = 'TOTAL';

    public static function parse(string $html): IssuerPage
    {
        $page = HtmlPage::load($html);
        $calendar = HtmlPage::tableUnder($page, self::MEETINGS);
        $structure = HtmlPage::tableUnder($page, self::STRUCTURE);
        $issue = HtmlPage::tableUnder($page, self::ISSUE);

        if ($calendar === null && $structure === null && $issue === null) {
            throw ParseFailure::at(self::SOURCE, 'neither a meetings calendar, a shareholder structure nor an issue');
        }

        return new IssuerPage(
            meetings: $calendar === null ? [] : self::meetings($calendar),
            // A structure with no table under it is a company that reported none.
            shareholders: $structure?->querySelector('table') === null ? [] : self::shareholders($structure),
            shareholdersAsOf: $structure === null || preg_match('/Din data: (\d{2}\.\d{2}\.\d{4})/u', HtmlPage::text($structure), $asOf) !== 1
                ? null
                : Values::date($asOf[1], self::SOURCE . ', ' . self::STRUCTURE),
            financialCalendar: self::financialCalendar(HtmlPage::tableUnder($page, self::FINANCIAL)),
            figures: self::figures($page, self::labelled($issue)),
            logoUrl: self::logo($page),
        );
    }

    /**
     * The company logo in the page head — an `<img>` whose id ends in "imglogo" (the rest of the id is
     * ASP.NET control plumbing that can change). Its `src` is returned as the page gives it, usually
     * relative; the caller resolves it against the page URL. Null when the page shows no logo.
     */
    private static function logo(HTMLDocument $page): ?string
    {
        foreach ($page->querySelectorAll('img') as $img) {
            if (! str_ends_with($img->getAttribute('id') ?? '', 'imglogo')) {
                continue;
            }

            $src = trim($img->getAttribute('src') ?? '');

            return $src === '' ? null : $src;
        }

        return null;
    }

    /** @return list<FinancialEvent> */
    private static function financialCalendar(?Element $calendar): array
    {
        $events = [];

        foreach ($calendar === null ? [] : HtmlTable::rows($calendar) as $at => $row) {
            $date = HtmlPage::text($row->querySelector('.evDate'));

            // The header row, and a calendar with nothing on it, have no date.
            if ($date === '') {
                continue;
            }

            $where = self::SOURCE . ', ' . self::FINANCIAL . ', row ' . $at;
            $events[] = new FinancialEvent(Values::romanianDate($date, $where), Values::name(HtmlPage::text($row->querySelector('p')), $where));
        }

        return $events;
    }

    /** @param array<string, string> $issue the "Informatii emisiune" table, as label => value */
    private static function figures(HTMLDocument $page, array $issue): IssuerFigures
    {
        $prices = self::labelled(HtmlPage::tableUnder($page, self::PRICES));
        $indicators = self::labelled(HtmlPage::tableUnder($page, self::INDICATORS));
        $head = [];

        // The page head's own little table — type, segment, category, state — has no heading of its own.
        foreach ($page->querySelectorAll('table') as $table) {
            $head = [...$head, ...self::labelled($table)];
        }

        $figure = static fn (string $text, string $where): ?string => Values::optionalDecimal($text === self::NONE ? '' : $text, self::SOURCE . ", {$where}");
        $decimal = static fn (array $from, string $label, string $section): ?string => $figure($from[$label] ?? '', "{$section}, {$label}");
        $count = static fn (string $label): ?int => ($issue[$label] ?? '') === '' ? null : Values::count($issue[$label], self::SOURCE . ', ' . self::ISSUE . ", {$label}");
        $bidAsk = array_map('trim', explode(self::BID_ASK, $prices['Bid / Ask'] ?? '', 2));
        $dividend = null;
        $dividendYear = null;

        foreach ($indicators as $label => $value) {
            if (preg_match('/\ADividend \((\d{4})\)\z/u', $label, $year) === 1) {
                $dividend = Values::optionalDecimal($value, self::SOURCE . ', ' . self::INDICATORS . ", {$label}");
                $dividendYear = (int) $year[1];
            }
        }

        $since = $issue['Data start tranzactionare'] ?? '';

        return new IssuerFigures(
            marketCap: $decimal($indicators, 'Capitalizare', self::INDICATORS),
            priceEarnings: $decimal($indicators, 'PER', self::INDICATORS),
            priceBook: $decimal($indicators, 'P/BV', self::INDICATORS),
            earningsPerShare: $decimal($indicators, 'EPS', self::INDICATORS),
            dividendYield: $decimal($indicators, 'DIVY', self::INDICATORS),
            lastDividend: $dividend,
            lastDividendYear: $dividendYear,
            week52High: $decimal($prices, 'Max. 52 saptamani', self::PRICES),
            week52Low: $decimal($prices, 'Min. 52 saptamani', self::PRICES),
            sharesTotal: $count('Numar total actiuni'),
            faceValue: $decimal($issue, 'Valoare Nominala', self::ISSUE),
            shareCapital: $decimal($issue, 'Capital social', self::ISSUE),
            tradingSince: $since === '' ? null : Values::date($since, self::SOURCE . ', ' . self::ISSUE . ', Data start tranzactionare'),
            segment: ($head['Segment:'] ?? '') === '' ? null : $head['Segment:'],
            category: ($head['Categorie:'] ?? '') === '' ? null : $head['Categorie:'],
            bid: $figure($bidAsk[0], self::PRICES . ', Bid'),
            ask: $figure($bidAsk[1] ?? '', self::PRICES . ', Ask'),
            couponRate: $decimal($issue, 'Cupon curent', self::ISSUE),
            yieldToMaturity: $decimal($issue, 'Randament (YTM)*', self::ISSUE),
            unitsIssued: $count('Instrumente emise'),
            issueValue: $decimal($issue, 'Valoare emisiune', self::ISSUE),
        );
    }

    /**
     * A table of label/value rows, as label => value — each row's first cell and its second. A row of
     * another shape is not one of them.
     *
     * @return array<string, string>
     */
    private static function labelled(?Element $table): array
    {
        $pairs = [];

        foreach ($table === null ? [] : HtmlTable::rows($table) as $row) {
            $cells = HtmlTable::cellsOf($row);

            if (count($cells) === 2 && $cells[0]->localName === 'td') {
                $pairs[HtmlPage::text($cells[0])] = HtmlPage::text($cells[1]);
            }
        }

        return $pairs;
    }

    /** @return list<Meeting> */
    private static function meetings(Element $calendar): array
    {
        $meetings = [];

        foreach (HtmlTable::rows($calendar) as $at => $row) {
            $date = HtmlPage::text($row->querySelector('b'));

            // The header row, and a calendar with nothing on it, have no date.
            if ($date === '') {
                continue;
            }

            $where = self::SOURCE . ', ' . self::MEETINGS . ', row ' . $at;
            $kind = trim(mb_substr(HtmlPage::text($row), mb_strlen($date)));

            $meetings[] = new Meeting(Values::romanianDate($date, $where), Values::name($kind, $where));
        }

        return $meetings;
    }

    /** @return list<Shareholder> */
    private static function shareholders(Element $structure): array
    {
        [$holder, $shares, $percent] = self::SHAREHOLDER_COLUMNS;
        $holders = [];

        foreach (HtmlTable::withHeaders($structure, self::SHAREHOLDER_COLUMNS, self::SOURCE . ', ' . self::STRUCTURE) as $at => $row) {
            $name = HtmlPage::text($row[$holder]);

            if ($name === self::TOTAL) {
                continue;
            }

            $where = self::SOURCE . ', ' . self::STRUCTURE . ', row ' . ($at + 1);
            $holders[] = new Shareholder(Values::name($name, $where), Values::count(HtmlPage::text($row[$shares]), $where), Values::decimal(HtmlPage::text($row[$percent]), $where));
        }

        return $holders;
    }
}
