<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

final readonly class TransactionSplitInput
{
    public function __construct(
        public int $amountMinor,
        public string $categoryCode,
        public ?string $linkedDomainType = null,
        public ?string $linkedDomainId = null,
    ) {
    }
}
