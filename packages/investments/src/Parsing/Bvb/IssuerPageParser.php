<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use Dom\Element;
use Kern\Investments\Parsing\Html\HtmlPage;
use Kern\Investments\Parsing\Html\HtmlTable;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Parsing\Values;

/**
 * An instrument's BVB page, read for its issuer: the "Calendar AGA" section — no column headers, so found
 * by its heading — and the "Structura actionariat" table, found by its columns. A page with neither is
 * not an instrument page and fails; a section with no rows is a company with nothing listed there.
 */
final class IssuerPageParser
{
    private const string SOURCE = 'BVB instrument page';

    private const string MEETINGS = 'Calendar AGA';

    private const string STRUCTURE = 'Structura actionariat';

    private const array SHAREHOLDER_COLUMNS = ['Actionar', 'Actiuni', 'Procent'];

    /** The structure's last line, which adds up the lines above it. */
    private const string TOTAL = 'TOTAL';

    public static function parse(string $html): IssuerPage
    {
        $page = HtmlPage::load($html);
        $calendar = HtmlPage::tableUnder($page, self::MEETINGS);
        $structure = HtmlPage::tableUnder($page, self::STRUCTURE);

        if ($calendar === null && $structure === null) {
            throw ParseFailure::at(self::SOURCE, 'neither a meetings calendar nor a shareholder structure');
        }

        return new IssuerPage(
            meetings: $calendar === null ? [] : self::meetings($calendar),
            // A structure with no table under it is a company that reported none.
            shareholders: $structure?->querySelector('table') === null ? [] : self::shareholders($structure),
            shareholdersAsOf: $structure === null || preg_match('/Din data: (\d{2}\.\d{2}\.\d{4})/u', HtmlPage::text($structure), $asOf) !== 1
                ? null
                : Values::date($asOf[1], self::SOURCE . ', ' . self::STRUCTURE),
        );
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
