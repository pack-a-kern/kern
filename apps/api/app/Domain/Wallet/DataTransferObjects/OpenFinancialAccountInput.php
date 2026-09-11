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
        /**
         * Required when `kind` is `TERM_DEPOSIT`, forbidden otherwise — `OpenFinancialAccount`
         * enforces both directions. That single check is what the old separate `deposits` table
         * could never express: it allowed a TERM_DEPOSIT account with no deposit behind it, and a
         * deposit belonging to no account.
         */
        public ?DepositTermsInput $depositTerms = null,
        /** Who opened it, for the audit chain. Null for a system import with no person behind it. */
        public ?string $actorId = null,
    ) {
    }
}
