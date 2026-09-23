<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

/**
 * The shape of the answer a value has in the law, which is what a reader is asked for and what is
 * checked against the paragraph it quotes.
 */
enum ParameterAnswer: string
{
    /** A rate in percent — "cota de impozit este de 10%". */
    case PERCENT = 'percent';

    /** A number of minimum wages — "nivelul de 60 de salarii minime brute pe țară". */
    case WAGES = 'wages';

    /** Several numbers of minimum wages, the bands of one rule — 6, 12 and 24. */
    case WAGES_LIST = 'wages_list';

    /** An amount in lei — "suma lunară de 4.000 lei". */
    case LEI = 'lei';

    /** What a reader is told to answer with. */
    public function shape(): string
    {
        return match ($this) {
            self::WAGES_LIST => '{"values": [<numere de salarii minime>], "citation": "Art. X alin. (Y)", "quote": "<propoziția exactă din text>"}',
            default => '{"value": <număr>, "citation": "Art. X alin. (Y)", "quote": "<propoziția exactă din text>"}',
        };
    }

    /** What the number means, said to the reader so it answers in the unit the value is stored in. */
    public function unit(): string
    {
        return match ($this) {
            self::PERCENT => 'procent (doar numărul, fără semnul %)',
            self::WAGES, self::WAGES_LIST => 'număr de salarii minime brute pe țară',
            self::LEI => 'sumă în lei (doar numărul)',
        };
    }
}
