<?php

declare(strict_types=1);

namespace Kern\Legislation\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use Kern\Legislation\Enums\NoteApplicabilityKind;
use Kern\Legislation\Parsing\RomanianDateParser;

/**
 * Reads what one of the portal's notes says about when its provision applies.
 *
 * A consolidation is dated by the day the portal's text changed; the amending act often says its
 * change applies later — "Potrivit alin. (2) al art. 10 din ORDONANȚA DE URGENȚĂ nr. 8 din 24
 * februarie 2026, publicată în MONITORUL OFICIAL nr. 147 din 25 februarie 2026, prevederile art. 6
 * pct. 1 se aplică începând cu anul fiscal 2026." This finds the acts a note quotes and every
 * sentence of the kinds `config/legislation.php` lists, each with the dates it gives and the words
 * it was read from, so whoever uses a date can see where it came from.
 *
 * Nothing is inferred: a note that says nothing it recognises gives no dates, and a kind whose date
 * the note does not state — a modified fiscal year, publication by an act the note does not name — gives none.
 *
 * @phpstan-type NoteAct array{type: string, number: string, date: string|null, journal_number: string, journal_date: string|null}
 * @phpstan-type NoteApplies array{kind: string, from: string|null, until: string|null, year: int|null, act: int|null, quote: string}
 * @phpstan-type ReadNote array{text: string, acts: list<NoteAct>, applies: list<NoteApplies>}
 */
final readonly class NoteReader
{
    /** @var list<array{kind: NoteApplicabilityKind, pattern: string}> */
    private array $patterns;

    private string $actPattern;

    public function __construct()
    {
        $fragments = [];

        foreach (config()->array('legislation.notes.fragments') as $name => $fragment) {
            $fragments['{' . $name . '}'] = '(?<' . $name . '>' . self::string($fragment) . ')';
        }

        $fragments['{until}'] = '(?<until>' . self::string(config('legislation.notes.fragments.date')) . ')';

        $patterns = [];

        foreach (config()->array('legislation.notes.applicability') as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('legislation.notes.applicability: every entry is a kind and a pattern.');
            }

            $patterns[] = [
                'kind' => NoteApplicabilityKind::from(self::string($entry['kind'] ?? null)),
                'pattern' => '/' . strtr(self::string($entry['pattern'] ?? null), $fragments) . '/iu',
            ];
        }

        $this->patterns = $patterns;
        // Inside the act's own pattern `{date}` is captured by the act's names, not "date".
        $this->actPattern = '/' . str_replace('{date}', self::string(config('legislation.notes.fragments.date')), config()->string('legislation.notes.act')) . '/iu';
    }

    /** @return ReadNote */
    public function read(string $note): array
    {
        $acts = [];
        $actOffsets = [];

        preg_match_all($this->actPattern, $note, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $actOffsets[] = $match[0][1];
            $acts[] = [
                'type' => (string) preg_replace('/\s+/u', ' ', mb_strtoupper($match['type'][0])),
                'number' => $match['number'][0],
                'date' => self::date($match['date'][0]),
                'journal_number' => $match['journal_number'][0],
                'journal_date' => self::date($match['journal_date'][0]),
            ];
        }

        $taken = [];
        $applies = [];

        foreach ($this->patterns as $pattern) {
            preg_match_all($pattern['pattern'], $note, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);

                if (self::overlaps($taken, $start, $end)) {
                    continue;
                }

                $taken[] = [$start, $end];
                $act = self::lastBefore($actOffsets, $start);
                $captured = static fn (string $name): ?string => isset($match[$name]) && $match[$name][1] >= 0 && $match[$name][0] !== '' ? $match[$name][0] : null;

                $applies[$start] = [
                    'kind' => $pattern['kind']->value,
                    ...self::dates($pattern['kind'], $captured, $act === null ? null : $acts[$act]['journal_date']),
                    'act' => $act,
                    'quote' => $match[0][0],
                ];
            }
        }

        ksort($applies);

        return ['text' => $note, 'acts' => $acts, 'applies' => array_values($applies)];
    }

    /**
     * @param  callable(string): (string|null)  $captured
     * @return array{from: string|null, until: string|null, year: int|null}
     */
    private static function dates(NoteApplicabilityKind $kind, callable $captured, ?string $published): array
    {
        $year = $captured('year') === null ? null : (int) $captured('year');
        $firstOf = static fn (int $month): ?string => $year === null ? null : sprintf('%04d-%02d-01', $year, $month);

        $from = match ($kind) {
            NoteApplicabilityKind::INCOME_MONTH => self::date('1 ' . ($captured('month') ?? '') . ' ' . ($year ?? '')),
            NoteApplicabilityKind::INCOME_YEAR, NoteApplicabilityKind::FISCAL_YEAR => $firstOf(1),
            NoteApplicabilityKind::MODIFIED_FISCAL_YEAR, NoteApplicabilityKind::UNTIL_DATE => null,
            NoteApplicabilityKind::QUARTER => $firstOf(1 + 3 * (int) array_search(mb_strtoupper($captured('quarter') ?? ''), ['I', 'II', 'III', 'IV'], true)),
            NoteApplicabilityKind::INCOME_MONTH_AFTER_PUBLICATION => $published === null ? null : (new DateTimeImmutable($published))->modify('first day of next month')->format('Y-m-d'),
            NoteApplicabilityKind::IN_FORCE_ON_PUBLICATION => $published,
            NoteApplicabilityKind::IN_FORCE, NoteApplicabilityKind::FROM_DATE, NoteApplicabilityKind::ON_DATE => self::date($captured('date') ?? ''),
        };

        return [
            'from' => $from,
            'until' => self::date($captured('until') ?? ''),
            'year' => $year,
        ];
    }

    private static function date(string $text): ?string
    {
        return RomanianDateParser::parse($text)?->format('Y-m-d');
    }

    /** @param list<array{0: int, 1: int}> $taken */
    private static function overlaps(array $taken, int $start, int $end): bool
    {
        foreach ($taken as [$from, $to]) {
            if ($start < $to && $end > $from) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $offsets */
    private static function lastBefore(array $offsets, int $position): ?int
    {
        $found = null;

        foreach ($offsets as $index => $offset) {
            if ($offset < $position) {
                $found = $index;
            }
        }

        return $found;
    }

    private static function string(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('legislation.notes: a pattern or fragment is missing.');
        }

        return $value;
    }
}
