<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Html;

use Dom\Element;
use Dom\HTMLDocument;
use Kern\Investments\Parsing\ParseFailure;

/**
 * "The table with these column headers", read into rows keyed by header — never by a column's position
 * or a generated id (ADR 0025 §6). A column moved by the source still reads; a column renamed or gone
 * fails, naming it.
 *
 * Only a table's own rows: one nested in a cell is its own table.
 */
final class HtmlTable
{
    /**
     * @param list<string> $headers
     *
     * @return list<array<string, Element>> one per data row, each cell under its header
     */
    public static function withHeaders(HTMLDocument|Element $scope, array $headers, string $where): array
    {
        foreach ($scope->querySelectorAll('table') as $table) {
            $rows = self::rows($table);
            $columns = $rows === [] ? [] : self::columns($rows[0], $headers);

            if ($columns !== null && $columns !== []) {
                return array_map(static fn (Element $row): array => self::cells($row, $columns, $where), array_slice($rows, 1));
            }
        }

        throw ParseFailure::at($where, 'no table with the columns ' . implode(', ', $headers));
    }

    /** @return list<Element> */
    public static function rows(Element $table): array
    {
        $own = [];

        foreach ($table->querySelectorAll('tr') as $row) {
            if ($row->closest('table') === $table) {
                $own[] = $row;
            }
        }

        return $own;
    }

    /** @return list<Element> */
    public static function cellsOf(Element $row): array
    {
        $cells = [];

        foreach ($row->children as $cell) {
            if ($cell->localName === 'td' || $cell->localName === 'th') {
                $cells[] = $cell;
            }
        }

        return $cells;
    }

    /**
     * Where each header stands in this row, or null when the row is not the header row asked for.
     *
     * @param list<string> $headers
     *
     * @return array<string, int>|null
     */
    private static function columns(Element $row, array $headers): ?array
    {
        $texts = array_map(HtmlPage::text(...), self::cellsOf($row));
        $columns = [];

        foreach ($headers as $header) {
            $at = array_search($header, $texts, true);

            if ($at === false) {
                return null;
            }

            $columns[$header] = $at;
        }

        return $columns;
    }

    /**
     * @param array<string, int> $columns
     *
     * @return array<string, Element>
     */
    private static function cells(Element $row, array $columns, string $where): array
    {
        $cells = self::cellsOf($row);

        return array_map(
            static fn (int $at): Element => $cells[$at] ?? throw ParseFailure::at($where, 'a row with fewer cells than its header'),
            $columns,
        );
    }
}
