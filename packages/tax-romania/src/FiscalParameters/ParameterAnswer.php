<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters;

/**
 * The shape of the answer a value has in the law, which is what a reader is asked for and what is
 * checked against the paragraph it names.
 *
 * A reader answers with numbers and the label of the paragraph that states them — never with the
 * law's own words. The words are ours already: the paragraph is in this application's copy of the
 * law, and a sentence copied back would only be the same sentence, mistyped or reflowed.
 */
enum ParameterAnswer: string
{
    /** A rate in percent — "cota de impozit este de 10%". */
    case PERCENT = 'percent';

    /** A number of minimum wages — "nivelul de 60 de salarii minime brute pe țară". */
    case WAGES = 'wages';

    /** Several numbers of minimum wages, the bands of one rule — 6, 12 and 24. */
    case WAGES_LIST = 'wages_list';

    /** The two ends of one base: not less than 6 minimum wages, not more than 72. */
    case WAGES_RANGE = 'wages_range';

    /**
     * One base, set either way — because the law has set it both ways.
     *
     * CASS on an independent activity's income is the income itself, between 6 and 60 minimum wages,
     * from 2024; in 2023 the same income was taxed on a step — 6, 12 or 24 — and until 2022 on one
     * level for everything. A rule told in advance which shape to expect can only answer in that
     * shape, and 2023 read as an interval says the base slides where the law says it steps. So the
     * reader is given both forms and names the one it found.
     */
    case WAGES_BASE = 'wages_base';

    /** An amount in lei — "suma lunară de 4.000 lei". */
    case LEI = 'lei';

    /**
     * A day of the year, as a deadline the law repeats every year — "până la data de 25 mai
     * inclusiv a anului următor": the day and the month, the year being the one the law says.
     */
    case DAY_MONTH = 'day_month';

    /**
     * Whether the law says something holds — "Nu sunt venituri impozabile următoarele: … veniturile
     * realizate din deținerea și transferul instrumentelor financiare care atestă datoria publică".
     */
    case YES_NO = 'yes_no';

    /** A number of days — "într-o perioadă mai mare de 365 de zile inclusiv de la data dobândirii". */
    case DAYS = 'days';

    /** What a reader is told to answer with. */
    public function shape(): string
    {
        return match ($this) {
            self::WAGES_LIST => '{"values": [<numbers of minimum wages>], "citation": "Art. X alin. (Y)"}',
            self::WAGES_RANGE => '{"floor": <number or null>, "floor_citation": "Art. X alin. (Y)", "cap": <number or null>, "cap_citation": "Art. X alin. (Y)"}',
            self::WAGES_BASE => 'one of these two forms, whichever the law sets:' . PHP_EOL
                . '    {"shape": "interval", "floor": <number>, "floor_citation": "Art. X alin. (Y)", "cap": <number>, "cap_citation": "Art. X alin. (Y)"}' . PHP_EOL
                . '    {"shape": "steps", "values": [<numbers, ascending>], "citation": "Art. X alin. (Y)"}',
            self::DAY_MONTH => '{"day": <number or null>, "month": <number or null>, "citation": "Art. X alin. (Y)"}',
            self::YES_NO => '{"value": <true, false or null>, "citation": "Art. X alin. (Y)"}',
            default => '{"value": <number or null>, "citation": "Art. X alin. (Y)"}',
        };
    }

    /** What the number means, said to the reader so it answers in the unit the value is stored in. */
    public function unit(): string
    {
        return match ($this) {
            self::PERCENT => 'a percentage (the number alone, without the % sign)',
            self::WAGES, self::WAGES_LIST => 'a number of minimum gross wages ("salarii minime brute pe țară")',
            self::WAGES_RANGE => 'two numbers of minimum gross wages: the lowest the base can be, and the highest',
            self::WAGES_BASE => 'numbers of minimum gross wages',
            self::LEI => 'an amount in lei (the number alone)',
            self::DAY_MONTH => 'a day of the month and the month as its number, 1 to 12 ("25 mai" is day 25, month 5)',
            self::YES_NO => 'true if the paragraph says it holds, false if it says it does not (true or false, never a number)',
            self::DAYS => 'a whole number of days (the number alone)',
        };
    }

    /** Whether one answer of this shape stands on two paragraphs, one per number. */
    public function isRange(): bool
    {
        return $this === self::WAGES_RANGE;
    }

    /**
     * Whether the answer is a day and a month: two numbers whose order is their meaning, and of which
     * the law writes only the day as a number — the month is a word.
     */
    public function isDayAndMonth(): bool
    {
        return $this === self::DAY_MONTH;
    }

    /**
     * Whether the answer is a yes or a no: no number the paragraph could be searched for, so what is
     * checked is that the paragraph named is one of those the reader was given.
     */
    public function isYesOrNo(): bool
    {
        return $this === self::YES_NO;
    }

    /** Whether the reply says which shape it is, the law having set this value both ways. */
    public function isEither(): bool
    {
        return $this === self::WAGES_BASE;
    }

    /**
     * Whether a reply of this kind is a range: the shape it names, where it names one.
     *
     * @param  array<string, mixed>  $reply
     */
    public function isRangeIn(array $reply): bool
    {
        return $this->isEither() ? ($reply['shape'] ?? null) === 'interval' : $this->isRange();
    }
}
