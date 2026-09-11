<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

/**
 * One account as a screen shows it.
 *
 * `ibanLast4` rather than the IBAN: the full value is encrypted under the subject's own DEK and is
 * needed for matching, never for display. Four digits identify an account to the person who owns it
 * without putting a complete bank identifier into a browser tab, a screenshot or a support ticket.
 *
 * `balanceMinor` comes from the most recent `account_balance_snapshots` row, not from the account —
 * an account has no balance of its own in this schema, only a history of observations, each with
 * the source that produced it and whether it was verified. `balanceAsOf` is therefore part of the
 * answer, not decoration: a balance without its observation date is a number of unknown age.
 */
final readonly class AccountSummary
{
    public function __construct(
        public string $id,
        public string $displayName,
        public string $institutionName,
        public string $kind,
        public string $currencyCode,
        public ?string $ibanLast4,
        public ?int $balanceMinor,
        public ?string $balanceAsOf,
        public ?string $balanceSource,
        public bool $balanceVerified,
        /**
         * Whether the balance includes movements this system added, rather than being an
         * observation on its own.
         *
         * A snapshot is what a bank said on a day; a derived balance is that plus the transactions
         * recorded since, or — with no snapshot at all — the transactions alone from a zero start.
         * Both are useful and only one is confirmed, so the screen has to be able to tell them
         * apart.
         */
        public bool $balanceIsDerived,
        public string $source,
        public ?string $openedAt,
        public ?string $closedAt,
        /**
         * The deposit half, null for every other kind of account.
         *
         * Carried on the same row rather than fetched from a second endpoint, because a term
         * deposit *is* an account — the separate `deposits` table and the separate screen it fed
         * are exactly what made the two look like different things.
         */
        public ?DepositFacts $deposit = null,
    ) {
    }

    public function isClosed(): bool
    {
        return $this->closedAt !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'institution_name' => $this->institutionName,
            'kind' => $this->kind,
            'currency_code' => $this->currencyCode,
            'iban_last4' => $this->ibanLast4,
            'balance_minor' => $this->balanceMinor,
            'balance_as_of' => $this->balanceAsOf,
            'balance_source' => $this->balanceSource,
            'balance_verified' => $this->balanceVerified,
            'balance_is_derived' => $this->balanceIsDerived,
            'source' => $this->source,
            'deposit' => $this->deposit?->toArray(),
            'opened_at' => $this->openedAt,
            'closed_at' => $this->closedAt,
            'closed' => $this->isClosed(),
        ];
    }
}
