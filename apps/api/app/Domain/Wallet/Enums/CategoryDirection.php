<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * Which way money filed under a category can move — `config/tax_categories.php`.
 *
 * The sign of a transaction's amount is its direction, so this is checked against the sign rather
 * than stored beside it.
 */
enum CategoryDirection: string
{
    case IN = 'in';
    case OUT = 'out';
    case EITHER = 'either';

    public function allows(int $amountMinor): bool
    {
        return match ($this) {
            self::IN => $amountMinor > 0,
            self::OUT => $amountMinor < 0,
            self::EITHER => true,
        };
    }
}
