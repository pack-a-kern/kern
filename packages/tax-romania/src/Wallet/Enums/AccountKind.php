<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/**
 * `financial_accounts.kind` — what a source *is*, which decides how transactions get into it.
 *
 * ## Two shapes, not six kinds of bank account
 *
 * The original list came from `BLUEPRINT.md` §6.3 and modelled banking relationships:
 * `BANK_CURRENT`, `BANK_SAVINGS`, an IBAN, an opening date. None of it earned its place — a tax
 * return asks for income by type and by country, never for which bank paid you. What a source is
 * actually *for* is narrower: it scopes statement imports so a re-import cannot double your income,
 * and it groups a balance.
 *
 * So the kinds split by how money arrives, which is the distinction that changes behaviour:
 *
 *  - **Streams** ({@see self::GENERAL}, {@see self::BROKER}, {@see self::CRYPTO}, {@see self::OTHER})
 *    receive many transactions over time — typed by hand or parsed out of a statement.
 *  - **Holdings** ({@see self::CASH}, {@see self::TERM_DEPOSIT}, {@see self::INVESTMENTS}) are a single
 *    declared value, or movements the system generates from declared terms. Nothing is ever typed into them: "I hold 5.000 RON in
 *    cash" is one statement of fact, and a deposit's movements are computed from its terms. Adding
 *    an arbitrary transaction to either is how the ledger stops reflecting anything real — and was
 *    possible until this enum could say so.
 *
 * `BANK_CURRENT`/`BANK_SAVINGS` are gone. Bank statements land in {@see self::GENERAL} along with
 * receipts, rentals and anything typed by hand; someone who genuinely wants two banks kept apart
 * can open a second {@see self::OTHER} source, which is a choice rather than a setup step everyone
 * pays.
 */
enum AccountKind: string
{
    /**
     * The one source every taxpayer gets, created with them and never deleted.
     *
     * The floor everything lands on: a typed transaction, a receipt, a rental payment, a bank
     * statement. Its whole purpose is that recording income needs no setup first.
     */
    case GENERAL = 'GENERAL';

    /** A declared cash holding. One value, one transaction, editable — never added to. */
    case CASH = 'CASH';

    /** Principal plus terms; the interest schedule is computed, never entered. */
    case TERM_DEPOSIT = 'TERM_DEPOSIT';

    /**
     * Where the money of bonds and shares arrives — coupons, dividends, repayments, sales — one account per
     * taxpayer, place held and currency, opened by the holding that needs it and never by a person, never
     * edited or deleted, and left off the Accounts screen (ADR 0025 §9). The lots themselves are `investment_lots`; the payments
     * are computed from the instrument's payouts, never typed.
     */
    case INVESTMENTS = 'INVESTMENTS';

    /** eToro, Interactive Brokers — a statement gets imported here. */
    case BROKER = 'BROKER';

    /** Binance and the like. Multi-asset by nature, which is why currency lives on the amount. */
    case CRYPTO = 'CRYPTO';

    /** Anything a person wants tracked separately from `GENERAL`. */
    case OTHER = 'OTHER';

    /**
     * Whether transactions may be recorded against this source directly.
     *
     * The one question the rest of the module asks this enum: a holding's entries are produced from
     * its own declaration, so both the form and the server refuse a manual transaction on one.
     */
    public function isStream(): bool
    {
        return match ($this) {
            self::GENERAL, self::BROKER, self::CRYPTO, self::OTHER => true,
            self::CASH, self::TERM_DEPOSIT, self::INVESTMENTS => false,
        };
    }

    /** The mirror of {@see self::isStream()}, named so call sites read as the rule they enforce. */
    public function isHolding(): bool
    {
        return ! $this->isStream();
    }
}
