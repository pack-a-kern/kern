<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

/**
 * The editable half of an account.
 *
 * Every field is nullable and null means **leave unchanged**, not "clear" — a PATCH that omits a
 * field must not erase it.
 *
 * `kind` is absent on purpose: it decides whether transactions may be recorded against this source
 * at all, so changing it would reinterpret what is already stored. Getting it wrong means deleting
 * the source and opening the right one, which is the honest correction.
 */
final readonly class UpdateFinancialAccountInput
{
    public function __construct(
        public ?string $actorId = null,
        public ?string $displayName = null,
        /** The transaction form's default. Not identity: nothing that calculates reads it. */
        public ?string $currencyCode = null,
        /** A new value for a `CASH` holding; its single transaction is updated in place. */
        public ?int $holdingAmountMinor = null,
        /** Supplied only for a `TERM_DEPOSIT`; replaces the terms and re-computes unpaid interest. */
        public ?DepositTermsInput $depositTerms = null,
    ) {
    }
}
