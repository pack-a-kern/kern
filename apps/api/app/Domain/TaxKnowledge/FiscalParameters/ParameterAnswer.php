<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

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

    /** An amount in lei — "suma lunară de 4.000 lei". */
    case LEI = 'lei';

    /** What a reader is told to answer with. */
    public function shape(): string
    {
        return match ($this) {
            self::WAGES_LIST => '{"values": [<numbers of minimum wages>], "citation": "Art. X alin. (Y)"}',
            self::WAGES_RANGE => '{"floor": <number or null>, "floor_citation": "Art. X alin. (Y)", "cap": <number or null>, "cap_citation": "Art. X alin. (Y)"}',
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
            self::LEI => 'an amount in lei (the number alone)',
        };
    }

    /** Whether one answer of this shape stands on two paragraphs, one per number. */
    public function isRange(): bool
    {
        return $this === self::WAGES_RANGE;
    }
}
