<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Enums;

/**
 * What kind of institution a bank is, as its national register says.
 *
 * ## Why this is not the ECB's `category`
 *
 * Because that column says `Credit Institution` for every row we keep, and means it: a cooperative in
 * Slatina, a branch of ING and Banca Comercială Română are all credit institutions and the ECB draws
 * no line between them. The line is drawn by whoever registered them — in Romania, by the Banca
 * Națională a României, in the number it assigns — and the ECB carries that number through unchanged.
 * That is why `RIAD_CODE` is letters here and digits for Germany and France.
 *
 * ## The cases are the European vocabulary, not Romania's
 *
 * A branch of a foreign bank and an electronic money institution mean the same thing in every member
 * state, because the directives define them. Only the codes that identify them are national, and
 * those live in `config/banks.php` keyed by country. So adding a country is a table of codes, and
 * these cases stay as they are.
 *
 * ## `UNKNOWN` is a real answer
 *
 * Four of the eighty-seven Romanian rows match nothing today: a development bank registered in
 * November 2025 under letters no regulation we found defines, a branch carrying a purely numeric
 * code, one code that looks mistyped at source, and a five-character stub from an archived list.
 * Calling those `BANK` would be asserting what the register does not say.
 */
enum BankKind: string
{
    /** A bank incorporated in the country it is registered in. */
    case BANK = 'BANK';

    /** A branch of a credit institution incorporated elsewhere — not a separate legal person. */
    case FOREIGN_BRANCH = 'FOREIGN_BRANCH';

    /**
     * A credit cooperative, or the central body its network is affiliated to.
     *
     * One case rather than two. The central body is a different thing — the forty Romanian
     * cooperatives carry its id inside their own codes — but nothing asks the question, and the code
     * still answers it for anyone who does.
     */
    case COOPERATIVE = 'COOPERATIVE';

    /** An electronic money institution. */
    case ELECTRONIC_MONEY = 'ELECTRONIC_MONEY';

    /** No code in this country's table matched — see the enum's docblock. */
    case UNKNOWN = 'UNKNOWN';
}
