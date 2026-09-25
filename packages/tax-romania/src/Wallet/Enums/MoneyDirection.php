<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/**
 * Which way money moved.
 *
 * Derived from the sign of `amount_minor` rather than stored beside it: the sign *is* the direction,
 * and a second field would eventually disagree with it. Used where a question is asked per direction
 * — what a counterparty means when they pay you as opposed to when you pay them.
 */
enum MoneyDirection: string
{
    case IN = 'IN';
    case OUT = 'OUT';

    public static function ofAmount(int $amountMinor): self
    {
        return $amountMinor < 0 ? self::OUT : self::IN;
    }

    public function allows(CategoryDirection $category): bool
    {
        return match ($category) {
            CategoryDirection::IN => $this === self::IN,
            CategoryDirection::OUT => $this === self::OUT,
            CategoryDirection::EITHER => true,
        };
    }
}
