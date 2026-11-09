<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/**
 * Why a payout due to a holding was not credited this run. Nothing is guessed in its place: the next
 * run asks again, and credits it once what was missing is there.
 */
enum SkippedPayout: string
{
    /** No approved `RO.INCOME_TAX.DIVIDEND_RATE` on the payment date, so the net cannot be known. */
    case NO_APPROVED_DIVIDEND_RATE = 'NO_APPROVED_DIVIDEND_RATE';

    /** A dividend without its ex-dividend date: who is owed it cannot be told. */
    case NO_EX_DATE = 'NO_EX_DATE';

    /** No amount per unit, and no period, rate and face value to compute one from. */
    case NO_AMOUNT = 'NO_AMOUNT';
}
