<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/** What an open lot's value was computed from — shown beside the value, which is never income. */
enum ValuationBasis: string
{
    /** The latest BVB price: per share, or percent of the face value for a bond. */
    case MARKET_PRICE = 'MARKET_PRICE';

    /** A Tezaur bond, never listed, and a Fidelis not yet trading: worth their face value. */
    case FACE_VALUE = 'FACE_VALUE';
}
