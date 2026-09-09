<?php

declare(strict_types=1);

namespace App\Domain\Rental\Services\Matching;

use App\Domain\Rental\DataTransferObjects\TransactionMatchCandidate;
use App\Domain\Rental\Enums\TransactionMatchStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finds candidate `financial_transactions` (`Domain/Wallet`'s table) rows for a `rental_payments`
 * row, via the plain query builder against the table name — **never** `App\Domain\Wallet\Models\
 * FinancialTransaction` — so this module needs no `deptrac.php` grant on `Wallet` (`docs/AGENTS.md`
 * §6). This is the same sanctioned shape `App\Domain\Subjects\Infrastructure\
 * EloquentSubjectDekRepository` already establishes for a one-off cross-table read
 * (`docs/handoff/011-identity-tenancy.md` "Key decisions" #2) — a genuine, documented interim
 * measure, not a precedent this module invented casually: `Domain/Wallet` exposes no DTO-returning
 * application service for reading its own transactions, and building one there is outside this
 * task's path scope (`app/Domain/Rental/**` only).
 *
 * Only plain, unencrypted columns are selected — `amount_minor`, `currency_code`, `booked_at`,
 * `account_id` — never `description_ciphertext`/`counterparty_ciphertext`, which this module has
 * no key material to decrypt and does not need to for a heuristic amount/date/currency match.
 *
 * ## Matching signals (documented here because this is the only place they are decided)
 *
 * A transaction is a candidate for a rental payment when **all** of:
 *  1. it belongs to the same `tax_subject_id`;
 *  2. its `currency_code` matches exactly;
 *  3. its `amount_minor` matches exactly (a rent receipt is assumed to be a full, unsplit credit —
 *     partial-payment reconciliation is not attempted by this heuristic);
 *  4. its `booked_at` falls within `self::DATE_WINDOW_DAYS` days of the payment's own `paid_at`
 *     (a bank often books a transfer a day or two off the date a tenant says they paid);
 *  5. it is not already the `CONFIRMED` match of a *different* `rental_payments` row (preventing
 *     the same real-world transaction from being confirmed against two different rent payments).
 *
 * Among the candidates surviving all five filters, the one with the smallest absolute distance
 * between `booked_at` and `paid_at` is returned; ties are broken by the transaction id's own
 * lexical order, purely for determinism in a test asserting a specific winner. This is a
 * heuristic, not a fiscal or financial fact — `App\Domain\Rental\Actions\
 * ProposeRentalPaymentTransactionMatch` never writes anything stronger than `PROPOSED` from its
 * result (`BLUEPRINT.md` §14.6's "never auto-confirm" principle, applied to a financial link).
 */
final class TransactionMatchCandidateFinder
{
    private const int DATE_WINDOW_DAYS = 3;

    public function findBest(
        string $taxSubjectId,
        int $amountMinor,
        string $currencyCode,
        DateTimeImmutable $paidAt,
    ): ?TransactionMatchCandidate {
        $windowStart = $paidAt->modify('-' . self::DATE_WINDOW_DAYS . ' days')->format('Y-m-d');
        $windowEnd = $paidAt->modify('+' . self::DATE_WINDOW_DAYS . ' days')->format('Y-m-d');

        $alreadyConfirmed = DB::table('rental_payments')
            ->where('transaction_match_status', TransactionMatchStatus::CONFIRMED->value)
            ->whereNotNull('transaction_id')
            ->pluck('transaction_id');

        /** @var list<object{id: string, account_id: string, booked_at: string, amount_minor: int, currency_code: string}> $rows */
        $rows = DB::table('financial_transactions')
            ->select(['id', 'account_id', 'booked_at', 'amount_minor', 'currency_code'])
            ->where('tax_subject_id', $taxSubjectId)
            ->where('currency_code', $currencyCode)
            ->where('amount_minor', $amountMinor)
            ->whereBetween('booked_at', [$windowStart, $windowEnd])
            ->whereNotIn('id', $alreadyConfirmed)
            ->get()
            ->all();

        if ($rows === []) {
            return null;
        }

        usort($rows, static function (object $a, object $b) use ($paidAt): int {
            $distanceA = (int) (new DateTimeImmutable($a->booked_at))->diff($paidAt)->days;
            $distanceB = (int) (new DateTimeImmutable($b->booked_at))->diff($paidAt)->days;

            return $distanceA <=> $distanceB ?: $a->id <=> $b->id;
        });

        $best = $rows[0];

        return new TransactionMatchCandidate(
            transactionId: $best->id,
            accountId: $best->account_id,
            bookedAt: $best->booked_at,
            amountMinor: $best->amount_minor,
            currencyCode: $best->currency_code,
        );
    }
}
