<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * What a document *is* — a filing facet, not a gate.
 *
 * This used to decide whether a reading survived: a type with no `ExtractionSchema` made the
 * validator reject the whole reply, so a model that answered `ANAF_DECISION` destroyed an otherwise
 * good read of the issuer, the recipient and every line item. That was backwards. A document is
 * also an archive entry — something to find again — and every document must be storable whatever
 * it turns out to be. {@see \App\Domain\Documents\Parsing\ExtractionSchema} now gives every type a
 * schema, and whether a document carries money is answered by its `payments`, not by its type.
 *
 * The list is deliberately short. Each case has to be describable in one line for the extraction
 * prompt, because a model choosing between bare enum names is guessing — which is exactly how a
 * Romanian invoice came back classified as an ANAF decision.
 */
enum DocumentType: string
{
    /** Factură — an obligation to pay, or to be paid. */
    case INVOICE = 'INVOICE';

    /** Bon fiscal or chitanță — proof that a payment happened. */
    case RECEIPT = 'RECEIPT';

    /**
     * Extras de cont — a header and a list of movements.
     *
     * Not `BANK_STATEMENT`: a broker's and a crypto exchange's statements have the same shape and
     * the same tax consequences, and naming the type after banks pushed them towards `OTHER`, where
     * the row-reading half of the schema would never have been offered.
     */
    case ACCOUNT_STATEMENT = 'ACCOUNT_STATEMENT';

    /** Anything from ANAF: decizie, declarație, notificare, obligație de plată. */
    case TAX_DOCUMENT = 'TAX_DOCUMENT';

    /** A contract between parties — rental, services, loan. */
    case CONTRACT = 'CONTRACT';

    /** Hotărâre AGA, decizie asociat unic, a dividend distribution. */
    case CORPORATE = 'CORPORATE';

    /** None of the above. The description is what makes it findable again. */
    case OTHER = 'OTHER';
}
