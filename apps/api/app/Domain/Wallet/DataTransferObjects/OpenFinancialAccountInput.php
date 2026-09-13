<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

use App\Domain\Wallet\Enums\AccountKind;

final readonly class OpenFinancialAccountInput
{
    public function __construct(
        public string $taxSubjectId,
        public AccountKind $kind,
        /** Null only for the general source, which the screen labels itself. */
        public ?string $displayName,
        /**
         * A default for the form, not a constraint. Every amount carries its own currency, and a
         * broker or a crypto source holds several at once — so this may be null and nothing that
         * calculates reads it.
         */
        public ?string $currencyCode = null,
        public ?string $metadataJson = null,
        /**
         * What a `CASH` holding is worth, in minor units.
         *
         * A cash source is one declared value, not a container to fill: it records a single
         * transaction for this amount and refuses any other. Required for `CASH`, forbidden for
         * every other kind — {@see \App\Domain\Wallet\Actions\OpenFinancialAccount} enforces both.
         */
        public ?int $holdingAmountMinor = null,
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
