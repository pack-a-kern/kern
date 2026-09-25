<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing;

use DateTimeImmutable;

/**
 * Parses the `"DD <Romanian month name> YYYY"` date form `legislatie.just.ro` uses in its
 * publication line (observed live 2026-09-08, e.g. `"19 iulie 2010"` inside
 * `"MONITORUL OFICIAL nr. 498 din 19 iulie 2010"` — see
 * `docs/handoff/018-legislation-ingestion.md` "Sources consulted"). This is a calendar-name
 * lookup, not a fiscal fact — `docs/AGENTS.md`'s "never invent legislation/dates" rule governs
 * *effective* dates and citations, not the twelve names of the months.
 *
 * Returns `null` on anything that does not match exactly — never a best-effort guess.
 */
final class RomanianDateParser
{
    /** @var array<string, int> */
    private const array MONTHS = [
        'ianuarie' => 1,
        'februarie' => 2,
        'martie' => 3,
        'aprilie' => 4,
        'mai' => 5,
        'iunie' => 6,
        'iulie' => 7,
        'august' => 8,
        'septembrie' => 9,
        'octombrie' => 10,
        'noiembrie' => 11,
        'decembrie' => 12,
    ];

    public static function parse(string $text): ?DateTimeImmutable
    {
        $normalized = mb_strtolower(trim($text));

        if (preg_match('/(\d{1,2})\s+([a-zăâîșț]+)\s+(\d{4})/u', $normalized, $matches) !== 1) {
            return null;
        }

        [, $day, $monthName, $year] = $matches;
        $month = self::MONTHS[$monthName] ?? null;

        if ($month === null) {
            return null;
        }

        $day = (int) $day;
        $year = (int) $year;

        if ($day < 1 || $day > 31) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $month, $day));

        return $date === false ? null : $date;
    }
}
