<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

/**
 * One source as a screen shows it.
 *
 * `balances` is a list, not a number. A source is a folder that may hold several currencies at once
 * — a broker holds euros and dollars, a crypto exchange holds several assets — so a single figure
 * labelled with one currency could only ever be right by accident, and the version this replaces
 * added euros to dollars and printed the result as euros. A bank source comes back with a list of
 * one and looks exactly as it always did.
 *
 * Every figure here is *derived* from the transactions recorded against the source. There is no
 * observed balance any more: nothing in a tax return asks for one, so nothing asks a person to type
 * one. When a statement parser exists it will carry a closing figure for free, and that is the
 * point at which an observation becomes worth storing again.
 */
final readonly class AccountSummary
{
    /**
     * @param  list<array{currency_code: string, amount_minor: int}>  $balances
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $kind,
        /** A default for the form only; null for a source that legitimately holds several. */
        public ?string $currencyCode,
        public array $balances,
        public string $source,
        /**
         * The deposit half, null for every other kind of source.
         *
         * Carried on the same row rather than fetched from a second endpoint, because a term
         * deposit *is* a source — the separate `deposits` table and the separate screen it fed
         * are exactly what made the two look like different things.
         */
        public ?DepositFacts $deposit = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'kind' => $this->kind,
            'currency_code' => $this->currencyCode,
            'balances' => $this->balances,
            'source' => $this->source,
            'deposit' => $this->deposit?->toArray(),
        ];
    }
}
