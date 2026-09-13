<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

/**
 * One meaning given to part of a transaction.
 *
 * A split rather than a field on the transaction, because one payment can be two things at once:
 * 4.500 RON arriving of which 4.000 is rent and 500 the tenant repaying a water bill is two answers,
 * taxed differently, and a single category would have to lose one of them.
 */
final readonly class TransactionSplitInput
{
    public function __construct(
        public int $amountMinor,
        public string $categoryCode,
        /**
         * Where the income came from, ISO 3166-1 alpha-2. Null means Romania.
         *
         * Beside the category rather than inside it: Declarația Unică declares foreign income in its
         * own section, per country *and* per type, so `RENT` + `DE` and `RENT` + `RO` are different
         * rows on the form and a `FOREIGN_RENT` code would collapse a distinction the form makes.
         */
        public ?string $countryCode = null,
        public ?string $linkedDomainType = null,
        public ?string $linkedDomainId = null,
    ) {
    }
}
