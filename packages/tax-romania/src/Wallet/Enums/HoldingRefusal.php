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
}
