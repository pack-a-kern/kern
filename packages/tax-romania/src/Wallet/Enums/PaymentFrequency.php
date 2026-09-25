<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/**
 * `deposits.payment_frequency` (`BLUEPRINT.md` §6.4 names the column, not its literal values).
 * `AT_MATURITY` covers the common "one lump interest payment when the term ends" deposit; the
 * other four are the standard periodic capitalisation/payment intervals a term deposit offers.
 * `Kern\TaxRomania\Wallet\Services\Interest\DepositPaymentScheduleBuilder` is the only place these
 * values are translated into calendar boundaries.
 */
enum PaymentFrequency: string
{
    case MONTHLY = 'MONTHLY';
    case QUARTERLY = 'QUARTERLY';
    case SEMI_ANNUAL = 'SEMI_ANNUAL';
    case ANNUAL = 'ANNUAL';
    case AT_MATURITY = 'AT_MATURITY';
}
