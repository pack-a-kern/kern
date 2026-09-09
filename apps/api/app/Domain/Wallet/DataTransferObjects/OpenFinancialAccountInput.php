<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

use App\Domain\Wallet\Enums\AccountKind;

final readonly class OpenFinancialAccountInput
{
    public function __construct(
        public string $taxSubjectId,
        public AccountKind $kind,
        public string $displayName,
        public string $currencyCode,
        public ?string $institutionName = null,
        public ?string $iban = null,
        public ?string $openedAt = null,
        public ?string $closedAt = null,
        public ?string $metadataJson = null,
    ) {
    }
}
