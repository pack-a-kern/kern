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
 * ## What this is *not*
 *
 * It is not a tax calculation and does not claim to be one. `taxable` is a label the screen uses to
 * group things a person is answering questions about — "this is income" versus "this is my own
 * money moving" — not a rule that decides what is owed. What is owed comes from a versioned
 * `TaxRuleVersion` resolved against approved sources, and nothing here is a substitute for that
 * (`docs/AGENTS.md` rule 3: no fiscal mapping invented in code).
 *
 * ## Foreign income
 *
 * There is no `FOREIGN_INCOME` category, deliberately. Declarația Unică declares foreign income in
 * its own section, **per country and per type** — foreign rent and foreign dividends are different
 * rows there, taxed differently and often subject to a treaty. Collapsing them into one category
 * would destroy exactly the distinction the form asks for. So the country is a second field on the
 * split (`country_code`), and a category like `RENT` carries `RO` or `DE` beside it.
 */

return [

    /*
     * Money coming in that a tax return may ask about.
     *
     * Ordered as a person is likely to meet them rather than alphabetically: rent and salary are
     * what most individuals have, capital gains and freelance income are the long tail.
     */
    'income' => [
        'RENT',
        'SALARY',
        'DIVIDENDS',
        'INTEREST',
        'CAPITAL_GAIN',
        'CRYPTO_GAIN',
        'FREELANCE',
        'PENSION',
        'ROYALTIES',
        'OTHER_INCOME',
    ],

    /*
     * Money moving that is not income at all.
     *
     * These exist so a person can finish the job. A transaction list with no honest way to say
     * "this was my own money moving between my own accounts" leaves rows permanently unclassified,
     * and an unclassified row is indistinguishable from one nobody has looked at yet — which is the
     * signal the whole screen depends on.
     */
    'not_income' => [
        'TRANSFER',
        'LOAN_RECEIVED',
        'LOAN_REPAID',
        'REIMBURSEMENT',
        'GIFT',
        'EXPENSE',
        'OTHER_NOT_INCOME',
    ],

];
