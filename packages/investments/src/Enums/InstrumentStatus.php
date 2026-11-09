<?php

declare(strict_types=1);

namespace Kern\Investments\Enums;

/** Where a security is in its life. Nothing read from a source is deleted; it changes status. */
enum InstrumentStatus: string
{
    /** Being subscribed: a government bond whose order is out and whose issue date has not come. */
    case ON_OFFER = 'ON_OFFER';
    case ACTIVE = 'ACTIVE';
    case MATURED = 'MATURED';

    /** No longer on the market it was listed on, without having matured. */
    case DELISTED = 'DELISTED';
}
