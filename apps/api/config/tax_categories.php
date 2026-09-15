<?php

declare(strict_types=1);

/**
 * What money can be, when a person says so.
 *
 * ## Why this exists
 *
 * A bank tells you 4.500 RON arrived and who sent it. It cannot tell you whether that is rent, a
 * friend repaying a loan, or a dividend — and the three are taxed completely differently, or not at
 * all. `transaction_splits.category_code` is where that answer is stored, and this file is the set
 * of answers a person may give.
 *
 * ## Why a config file and not an enum
 *
 * Adding a category is not a code change. `config/counties.php` set the precedent for the same
 * reason: this is a vocabulary, and a vocabulary grows. A PHP enum would make every addition a
 * deployment, and the set is validated against this file on the way in either way.
 *
 * ## Which way the money moves
 *
 * Each code names the direction it can have: `in`, `out` or `either`
 * (`App\Domain\Wallet\Enums\CategoryDirection`). Income only ever arrives and an expense only ever
 * leaves, so a dividend paid out of an account is a mistake the server refuses rather than a figure
 * a return would later read. A loan or a transfer genuinely goes both ways.
 *
 * ## What this is *not*
 *
 * It is not a tax calculation and does not claim to be one. The grouping is a label the screen uses
 * — "this is income" versus "this is my own money moving" — not a rule that decides what is owed.
 * What is owed comes from a versioned `TaxRuleVersion` resolved against approved sources, and nothing
 * here is a substitute for that (`docs/AGENTS.md` rule 3: no fiscal mapping invented in code).
 *
 * ## Foreign income
 *
 * There is no `FOREIGN_INCOME` category, deliberately. Declarația Unică declares foreign income in
 * its own section, **per country and per type** — foreign rent and foreign dividends are different
 * rows there, taxed differently and often subject to a treaty. Collapsing them into one category
 * would destroy exactly the distinction the form asks for. So the country is a second field on the
 * split (`country_code`), and a category like `DIVIDENDS` carries `RO` or `DE` beside it.
 */

return [

    /*
     * Money coming in that a tax return may ask about.
     *
     * The ones a person declares from their own records come first. `RENT` and `SALARY_PENSION` are
     * last because neither is declared from the money that arrived: rent is counted from the
     * contract on the Rentals screen whether or not the tenant paid, and tax on a salary or a
     * pension is withheld by whoever pays it. They are here so that money can still be named, and
     * leave the worklist.
     */
    'income' => [
        'DIVIDENDS' => 'in',
        'INTEREST' => 'in',
        'CAPITAL_GAIN' => 'in',
        'CRYPTO_GAIN' => 'in',
        'FREELANCE' => 'in',
        'ROYALTIES' => 'in',
        'OTHER_INCOME' => 'in',
        'RENT' => 'in',
        'SALARY_PENSION' => 'in',
    ],

    /*
     * Money moving that is not income at all.
     *
     * These exist so a person can finish the job. A transaction list with no honest way to say
     * "this was my own money moving between my own accounts" leaves rows permanently unclassified,
     * and an unclassified row is indistinguishable from one nobody has looked at yet — which is the
     * signal the whole screen depends on.
     *
     * One `LOAN` and one `LOAN_REPAYMENT` rather than a code for each side: lent or borrowed is
     * already said by the direction. `NOT_INCOME` covers everything else that arrives without a tax
     * effect — a gift, a refund, an expense someone paid back.
     */
    'not_income' => [
        'TRANSFER' => 'either',
        'LOAN' => 'either',
        'LOAN_REPAYMENT' => 'either',
        'EXPENSE' => 'out',
        'NOT_INCOME' => 'in',
    ],

    /*
     * Offered already chosen when money moves in the category's only direction and nothing else has
     * been chosen. Almost everything that leaves an account is spending, and making a person pick
     * the obvious answer on every receipt is work for nothing; income is never assumed.
     */
    'preselected' => [
        'EXPENSE',
    ],

];
