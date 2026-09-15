<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * A category given to money moving the way it cannot: income paid out, or an expense received.
 *
 * Refused rather than stored, because a return reads the category — a dividend recorded on money
 * that left an account would be declared as income nobody received.
 */
final class CategoryDirectionException extends InvalidArgumentException
{
    public static function forCategory(string $categoryCode, int $amountMinor): self
    {
        $direction = $amountMinor < 0 ? 'out of' : 'into';

        return new self("Category {$categoryCode} cannot be given to money moving {$direction} an account.");
    }
}
