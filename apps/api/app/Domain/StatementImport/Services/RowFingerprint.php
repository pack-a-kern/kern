<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\Normalizer;

/**
 * What makes importing the same statement twice add nothing.
 *
 * ## Why a fingerprint and not the existing duplicates view
 *
 * The duplicates view finds *lookalikes* — same day, same amount, similar counterparty — and asks a
 * person to decide. That is exactly right for "a receipt I typed and the statement row for it", and
 * exactly wrong for "the same file uploaded twice", where nobody should be asked anything.
 *
 * So a row carries a fingerprint of what the bank actually printed, and the ledger's existing
 * uniqueness on `(account_id, external_id_blind_index)` refuses a second copy at the database.
 *
 * ## What goes into it
 *
 * The account and the bank, the date, the amount and currency, and the normalised text. The bank is
 * in it because General holds several banks' money: the same amount on the same day from two banks
 * is two payments, and a fingerprint that ignored the bank would silently drop one.
 *
 * ## Why an occurrence number
 *
 * Two coffees of 12,00 lei on the same day at the same shop are two real payments with identical
 * text. Numbering the occurrences within the file keeps both — and a re-import of the same file
 * produces the same numbers, so both are still recognised rather than added again.
 */
final readonly class RowFingerprint
{
    public function __construct(private BlindIndexHasher $blindIndex)
    {
    }

    /**
     * The fingerprints for a whole statement, in row order.
     *
     * Computed for the file as a unit precisely because of the occurrence numbering: a row cannot
     * know it is the second identical one without seeing the ones before it.
     *
     * @param  list<array{booked_at: ?string, amount_minor: ?int, currency_code: ?string, text: string, reference: string}>  $rows
     * @return list<?string> null where the row has no date or amount to fingerprint
     */
    public function forRows(array $rows, string $accountId, ?string $bankId): array
    {
        $seen = [];
        $fingerprints = [];

        foreach ($rows as $row) {
            if ($row['booked_at'] === null || $row['amount_minor'] === null) {
                $fingerprints[] = null;

                continue;
            }

            // The bank's own reference, where the export prints one, is the best identity a row can
            // have: it is what the bank itself calls this movement.
            $identity = trim($row['reference']) !== ''
                ? Normalizer::text($row['reference'])
                : Normalizer::text($row['text']);

            $base = implode('|', [
                $accountId,
                $bankId ?? '',
                $row['booked_at'],
                (string) $row['amount_minor'],
                strtoupper($row['currency_code'] ?? ''),
                $identity,
            ]);

            $occurrence = ($seen[$base] ?? 0) + 1;
            $seen[$base] = $occurrence;

            $fingerprints[] = $this->blindIndex->compute($base . '|' . $occurrence);
        }

        return $fingerprints;
    }
}
