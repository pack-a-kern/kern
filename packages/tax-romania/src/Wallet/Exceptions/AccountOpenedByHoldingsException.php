<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use RuntimeException;

/**
 * An investments account was edited or deleted. A holding opens it — one per place held and currency —
 * and its coupons, dividends, repayments and sales are what a tax is computed from: renamed, it is no
 * longer found by its place; deleted, it would take every holding and payment in it along (ADR 0025 §9).
 */
final class AccountOpenedByHoldingsException extends RuntimeException
{
    public const string REASON = 'ACCOUNT_OPENED_BY_HOLDINGS';

    public static function cannotChange(): self
    {
        return new self('This account is opened and kept by your holdings; it cannot be changed or deleted.');
    }
}
