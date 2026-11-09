<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;
use Kern\TaxRomania\Wallet\Enums\HoldingRefusal;

/** A holding that cannot be added or sold as asked; nothing was written. */
final class HoldingRefusedException extends InvalidArgumentException
{
    private function __construct(public readonly HoldingRefusal $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(HoldingRefusal $reason): self
    {
        return new self($reason, match ($reason) {
            HoldingRefusal::NOT_AN_INVESTMENTS_ACCOUNT => 'Holdings are kept in an investments account.',
            HoldingRefusal::INSTRUMENT_NOT_OFFERED => 'Only an instrument on offer or trading can be added.',
            HoldingRefusal::TEZAUR_ENDS_AT_MATURITY => 'A Tezaur bond cannot be sold early; it is repaid at maturity.',
            HoldingRefusal::NOT_ENOUGH_UNITS => 'The account does not hold that many units of the instrument on that day.',
            HoldingRefusal::NO_APPROVED_SECURITIES_RATE => 'A gain on a security is withheld at rates a person has to approve first; none is approved for that day.',
            HoldingRefusal::AMOUNT_TOO_LARGE => 'The amount is larger than a holding can be.',
            HoldingRefusal::FEES_EXCEED_PROCEEDS => 'The sale fees are larger than what the units sold for.',
            HoldingRefusal::REFERENCE_IN_USE => 'That broker reference belongs to another holding.',
            HoldingRefusal::SOLD_BEFORE_A_CREDITED_PAYMENT => 'The units were credited a payment after that day, so they were still held then.',
        });
    }
}
