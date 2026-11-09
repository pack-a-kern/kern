<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/**
 * `investment_lots.held_at` — where a lot is kept. Which bank or which broker is the account the lot is
 * in; a broker integrated later matches its positions against this and the lot's instrument.
 */
enum HeldAt: string
{
    /** Tezaur, bought from the Treasury, at the post office or on ghiseul.ro. */
    case TREASURY = 'TREASURY';
    case BANK = 'BANK';
    case BROKER = 'BROKER';
}
