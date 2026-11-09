<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/** Why a holding was not added or not sold — the `reason` the API answers with. */
enum HoldingRefusal: string
{
    case NOT_AN_INVESTMENTS_ACCOUNT = 'NOT_AN_INVESTMENTS_ACCOUNT';

    /** Only an issue on offer or trading may be bought; one matured or delisted may not. */
    case INSTRUMENT_NOT_OFFERED = 'INSTRUMENT_NOT_OFFERED';

    /** A Tezaur bond is not traded: the Treasury repays it at maturity, and not before. */
    case TEZAUR_ENDS_AT_MATURITY = 'TEZAUR_ENDS_AT_MATURITY';

    /** More units than the account holds of the instrument, bought by the day of the sale. */
    case NOT_ENOUGH_UNITS = 'NOT_ENOUGH_UNITS';

    /** A gain on a share or a company bond, and no approved rate or holding period to say what was withheld. */
    case NO_APPROVED_SECURITIES_RATE = 'NO_APPROVED_SECURITIES_RATE';

    /** A purchase, a sale or its fees past `wallet.holdings.max_amount_minor`. */
    case AMOUNT_TOO_LARGE = 'AMOUNT_TOO_LARGE';

    /** Sale fees larger than what the units sold for: a sale that pays to give its units away is a mistake. */
    case FEES_EXCEED_PROCEEDS = 'FEES_EXCEED_PROCEEDS';

    /** The broker's reference is another holding's — in another account, or of another instrument. */
    case REFERENCE_IN_USE = 'REFERENCE_IN_USE';

    /** Sold on a day before a payment the units were already credited: the units held it, so they were held then. */
    case SOLD_BEFORE_A_CREDITED_PAYMENT = 'SOLD_BEFORE_A_CREDITED_PAYMENT';
}
