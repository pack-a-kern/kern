<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

/**
 * The editable half of an account.
 *
 * Every field is nullable and null means **leave unchanged**, not "clear" — a PATCH that omits
 * `iban` must not erase one. The single exception is `closedAt`, where an empty string reopens an
 * account, because "no closing date" is itself a value a person needs to be able to set.
 *
 * `kind` and `currencyCode` are absent on purpose. Both are part of an account's identity: every
 * transaction and interest event recorded against it is denominated in that currency and shaped by
 * that kind, so changing either would silently reinterpret figures already stored. Getting it wrong
 * means deleting the account and opening the right one, which is the honest correction.
 */
final readonly class UpdateFinancialAccountInput
{
    public function __construct(
        public ?string $actorId = null,
        public ?string $displayName = null,
        public ?string $institutionName = null,
        public ?string $iban = null,
        public ?string $openedAt = null,
        /** An empty string reopens the account; null leaves the closing date as it is. */
        public ?string $closedAt = null,
        /** Supplied only for a `TERM_DEPOSIT`; replaces the terms and re-computes unpaid interest. */
        public ?DepositTermsInput $depositTerms = null,
    ) {
    }
}
