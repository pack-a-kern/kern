<?php

declare(strict_types=1);

namespace Kern\Investments\Enums;

/** The Ministry of Finance's programmes for selling government bonds to people. */
enum Programme: string
{
    /** Listed on BVB, with an ISIN; bought through a bank or a broker. */
    case FIDELIS = 'FIDELIS';

    /** Never listed, no ISIN, an issue code instead; bought from the Treasury or the post office. */
    case TEZAUR = 'TEZAUR';
}
