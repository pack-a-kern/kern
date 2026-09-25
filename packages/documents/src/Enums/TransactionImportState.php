<?php

declare(strict_types=1);

namespace Kern\Documents\Enums;

/**
 * Whether this document may still become a wallet transaction, and if not, why not.
 *
 * The rule it encodes: **a reading nobody accepted never becomes money.** A model proposes, a
 * person accepts, and only then is there anything to import. Skipping the middle step would make
 * the review screen decorative — the figures would reach `financial_transactions` whether or not
 * anybody had looked at them, which is exactly what `BLUEPRINT.md` §14.6 forbids.
 *
 * Computed on the server and sent to the screen, so the button a person sees and the rule the
 * endpoint enforces are the same rule. A client-side copy would drift the first time either moved,
 * and the direction it would drift is a button that promises something the server then refuses.
 */
enum TransactionImportState: string
{
    /** Still in the pipeline, or refused. There is nothing to import from it yet — or ever. */
    case UNAVAILABLE = 'UNAVAILABLE';

    /**
     * Nothing has been agreed to yet — a reading is still a proposal, or none was produced at all.
     *
     * The two look different on the review screen, which offers Accept for the first and Retry for
     * the second, but they answer this question the same way: there is nothing to import.
     */
    case AWAITING_ACCEPTANCE = 'AWAITING_ACCEPTANCE';

    /** A person accepted a reading. That reading is what an import draws its figures from. */
    case AVAILABLE = 'AVAILABLE';

    /**
     * It already produced its record — its transactions, or for a rental contract the rental made
     * from it. A document is imported once; a second pass would duplicate.
     */
    case IMPORTED = 'IMPORTED';
}
