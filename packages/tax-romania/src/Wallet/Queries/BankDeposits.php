<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Kern\TaxRomania\Wallet\DataTransferObjects\ConvertedTotal;

/**
 * The running deposits at one bank: how many, how much, and what they will earn.
 *
 * `bankId`, `name` and `code` are null together for deposits recorded before banks were imported —
 * grouped on their own rather than dropped, so every running deposit is in exactly one group.
 */
final readonly class BankDeposits
{
    /**
     * @param  array<string, int>  $principal  per currency
     * @param  array<string, int>  $interestAtMaturity  per currency, gross, over each whole term
     */
    public function __construct(
        public ?string $bankId,
        public ?string $name,
        /** The 4-letter code from the bank's BIC, or null where none is mapped. */
        public ?string $code,
        public int $depositCount,
        public array $principal,
        public array $interestAtMaturity,
        /** The principal in the display currency — what the groups are ordered by. */
        public ConvertedTotal $converted,
        public string $nextMaturityAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bank_id' => $this->bankId,
            'name' => $this->name,
            'code' => $this->code,
            'deposit_count' => $this->depositCount,
            'principal' => $this->principal,
            'interest_at_maturity' => $this->interestAtMaturity,
            'converted' => $this->converted->toArray(),
            'next_maturity_at' => $this->nextMaturityAt,
        ];
    }
}
