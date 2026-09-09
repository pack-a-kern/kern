<?php

declare(strict_types=1);

namespace App\Domain\Rental\Enums;

/**
 * `rental_payments.transaction_match_status` — the state of the (optional) link between a
 * `rental_payments` row and a `financial_transactions` row.
 *
 * `UNMATCHED` — no candidate transaction attached (either matching hasn't run, or it found none).
 * `PROPOSED`  — `App\Domain\Rental\Actions\ProposeRentalPaymentTransactionMatch`'s heuristic found
 *               a candidate; `transaction_id` is set but this is **not** a confirmed financial
 *               fact (`BLUEPRINT.md` §14.6's "never auto-confirm" principle, applied here to a
 *               financial link rather than a document extraction).
 * `CONFIRMED` — a human explicitly confirmed the proposed link
 *               (`App\Domain\Rental\Actions\ConfirmRentalPaymentTransactionMatch`). Only a
 *               `CONFIRMED` link should ever be treated as ground truth by a future consumer.
 * `REJECTED`  — a human explicitly rejected the proposed link
 *               (`App\Domain\Rental\Actions\RejectRentalPaymentTransactionMatch`). `transaction_id`
 *               is deliberately left in place (the rejected candidate stays visible for audit),
 *               matching `App\Domain\Documents\Models\DocumentExtraction`'s precedent of keeping
 *               `result_ciphertext` regardless of the review decision.
 */
enum TransactionMatchStatus: string
{
    case UNMATCHED = 'UNMATCHED';
    case PROPOSED = 'PROPOSED';
    case CONFIRMED = 'CONFIRMED';
    case REJECTED = 'REJECTED';
}
