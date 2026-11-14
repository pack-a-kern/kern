<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing;

use Illuminate\Support\Str;
use Normalizer;

/**
 * The rules for a section's text, in one place: where its tables sit, what it reads as when searched,
 * when it was last amended, and when two versions of it say the same thing.
 *
 * ## Tables
 *
 * A table is kept as rows of cells beside the text, and the text holds a marker line where it
 * stood — so the page draws the table in place, and the income-tax brackets stay a table rather than
 * becoming one line in which no value can be told from its row. Searching, chunking and quoting read
 * {@see withTables()}, which writes each row out as its cells, so "66.750" is still found.
 *
 * ## Amendment notes
 *
 * The portal prints, inside a provision, when and by what it was last changed:
 * "(la 01-01-2025, Alineatul (2) … a fost modificat de Punctul 22, Articolul I din ORDONANȚA …)".
 * That is the portal's commentary, not the law's words. It stays in the text a person reads, gives
 * {@see amendedOn()} its date, and is left out of {@see fingerprint()}.
 */
final class SectionText
{
    /** A table's place in the text, on a line of its own. Internal: the page replaces it. */
    private const string TABLE_MARKER = '[[table:%d]]';

    private const string TABLE_MARKER_PATTERN = '/^\[\[table:(\d+)\]\]$/m';

    private const string CELL_SEPARATOR = ' | ';

    /** Where an amendment note opens: `(la 01-01-2025,`. */
    private const string NOTE_OPENING = '/\(la (\d{2})-(\d{2})-(\d{4}),/u';

    /** A word, or a number with its decimal and thousands marks and its `^1` — the unit both readings keep. */
    private const string TOKEN = '/\d+(?:[.,]\d+)*(?:\^\d+)?|\p{L}+/u';

    /** The two spellings Romanian text is found in, cedilla and comma below, read as one. */
    private const array SAME_LETTER = ['ş' => 'ș', 'Ş' => 'Ș', 'ţ' => 'ț', 'Ţ' => 'Ț'];

    /** The marker for the `$number`th table of a section, counted from 1. */
    public static function tableMarker(int $number): string
    {
        return sprintf(self::TABLE_MARKER, $number);
    }

    /**
     * The text with every table written out, one row per line, cells separated — the lines a cell stacks
     * a space apart, so a row stays one line.
     *
     * @param  list<list<list<string>>>  $tables
     */
    public static function withTables(string $text, array $tables): string
    {
        if ($tables === []) {
            return $text;
        }

        return (string) preg_replace_callback(
            self::TABLE_MARKER_PATTERN,
            static function (array $match) use ($tables): string {
                $rows = $tables[(int) $match[1] - 1] ?? [];

                return implode("\n", array_map(
                    static fn (array $row): string => implode(self::CELL_SEPARATOR, array_map(static fn (string $cell): string => str_replace("\n", ' ', $cell), $row)),
                    $rows,
                ));
            },
            $text,
        );
    }

    /** The date of the latest amendment note in the text, as `Y-m-d`, or null when it has none. */
    public static function amendedOn(string $text): ?string
    {
        $latest = null;

        if (preg_match_all(self::NOTE_OPENING, $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as [, $day, $month, $year]) {
                if (checkdate((int) $month, (int) $day, (int) $year)) {
                    $date = "{$year}-{$month}-{$day}";
                    $latest = $latest === null || $date > $latest ? $date : $latest;
                }
            }
        }

        return $latest;
    }

    /**
     * Whether two versions of a provision say the same thing: a hash of its words and numbers only.
     *
     * Formatting, punctuation, case, the two spellings of ș and ț, a decimal comma against a decimal
     * point, and the portal's amendment notes all leave it unchanged. A changed word or number — 60
     * salaries becoming 72 — changes it. For telling versions apart, never for showing: the text a
     * person reads and quotes is always the text as published.
     */
    public static function fingerprint(string $text): string
    {
        $plain = Normalizer::normalize(self::withoutNotes($text), Normalizer::FORM_C);
        $plain = mb_strtolower(strtr(is_string($plain) ? $plain : $text, self::SAME_LETTER));

        preg_match_all(self::TOKEN, $plain, $tokens);

        return hash('sha256', implode(' ', array_map(
            static fn (string $token): string => str_replace(',', '.', $token),
            $tokens[0],
        )));
    }

    /**
     * The text as a search compares it: its words and numbers, lower case, without diacritics, one
     * space apart — "Cota de contribuţie … este de 10%" reads "cota de contributie este de 10". What
     * a phrase to look for is written in (`config/fiscal_parameters.php`), so it matches however the
     * portal spelled the letters, broke the lines or placed the punctuation.
     */
    public static function searchable(string $text): string
    {
        preg_match_all(self::TOKEN, Str::lower(Str::ascii($text)), $tokens);

        return implode(' ', $tokens[0]);
    }

    /** The text without its amendment notes — each note removed up to its own closing bracket. */
    private static function withoutNotes(string $text): string
    {
        $kept = '';
        $offset = 0;

        while (preg_match(self::NOTE_OPENING, $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $kept .= substr($text, $offset, $start - $offset);
            $depth = 0;
            $end = strlen($text);

            // A note quotes provisions — "Alineatul (2)" — so its end is the bracket that balances
            // its opening one, not the first one found.
            for ($index = $start; $index < strlen($text); $index++) {
                $depth += match ($text[$index]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    $end = $index + 1;

                    break;
                }
            }

            $offset = $end;
        }

        return $kept . substr($text, $offset);
    }
}
