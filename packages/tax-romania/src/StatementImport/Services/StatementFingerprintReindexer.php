<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Services;

use Illuminate\Support\Facades\DB;
use Kern\Platform\Crypto\BlindIndex\Contracts\BlindIndexReindexer;
use Kern\Platform\Crypto\BlindIndex\DataTransferObjects\ReindexOutcome;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;

/**
 * Re-keys the one blind index that is not a hash of a single column: a statement line's fingerprint.
 *
 * ## Why this needs a class of its own
 *
 * Every other blind index is `hash(key, one value from this row)`, so re-keying is row by row.
 * A fingerprint is `hash(key, account | bank | date | amount | currency | text | occurrence)`, and
 * the occurrence is the number of times that exact combination has appeared *earlier in the same
 * file* — so a row cannot be re-keyed without the rows before it. {@see RowFingerprint} says so:
 * "Computed for the file as a unit precisely because of the occurrence numbering." This walks whole
 * imports, in `row_number` order, for that reason.
 *
 * ## Why the inputs are all still here
 *
 * Because the import kept them. `description_ciphertext` is the `text` the fingerprint was built
 * from, `counterparty_ciphertext` the counterparty appended to it, `reference_ciphertext` the bank's
 * own reference, and `row_number` the order. The `''`/`null` mapping is the one detail that has to be
 * exact: `ApplyColumnMapping` stores an empty counterparty and an empty reference as null, and
 * reconstructing them as null rather than `''` would change the hashed string and produce a
 * fingerprint that recognises nothing.
 *
 * ## Both places it is stored
 *
 * The staged row keeps it in `fingerprint`, and the transaction the row became keeps the same value
 * in `external_id_blind_index` — that column's uniqueness per account is what makes importing the
 * same file twice write nothing ({@see \Kern\TaxRomania\Wallet\Actions\RecordImportedTransactions}).
 * Both are written here, through `transaction_id`, because re-keying one and not the other would
 * leave the duplicate check comparing values from two different key versions.
 */
final readonly class StatementFingerprintReindexer implements BlindIndexReindexer
{
    public function __construct(private RowFingerprint $fingerprints)
    {
    }

    public function label(): string
    {
        return 'statement_import_rows.fingerprint';
    }

    public function reindex(int $batch): ReindexOutcome
    {
        $examined = 0;
        $rewritten = 0;
        $unrecomputable = 0;

        StatementImport::query()
            ->withoutGlobalScope(TenantScope::class)
            ->orderBy('id')
            ->chunkById($batch, function (mixed $imports) use (&$examined, &$rewritten, &$unrecomputable): void {
                /** @var iterable<StatementImport> $imports */
                foreach ($imports as $import) {
                    [$seen, $written, $missed] = $this->reindexImport($import);
                    $examined += $seen;
                    $rewritten += $written;
                    $unrecomputable += $missed;
                }
            });

        return new ReindexOutcome($this->label(), $examined, $rewritten, $unrecomputable);
    }

    /**
     * One import, whole, in row order.
     *
     * @return array{0: int, 1: int, 2: int} examined, rewritten, unrecomputable
     */
    private function reindexImport(StatementImport $import): array
    {
        $rows = StatementImportRow::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('import_id', $import->id)
            ->orderBy('row_number')
            ->get();

        if ($rows->isEmpty()) {
            return [0, 0, 0];
        }

        $fingerprints = $this->fingerprints->forRows(
            array_values($rows->map(static fn (StatementImportRow $row): array => [
                'booked_at' => $row->booked_at?->format('Y-m-d'),
                'amount_minor' => $row->amount_minor === null ? null : (int) $row->amount_minor,
                'currency_code' => $row->currency_code,
                'text' => ((string) $row->description_ciphertext) . ' ' . ((string) $row->counterparty_ciphertext),
                'reference' => (string) $row->reference_ciphertext,
            ])->all()),
            $import->account_id,
            $import->bank_id,
        );

        $examined = 0;
        $rewritten = 0;
        $unrecomputable = 0;

        DB::transaction(function () use ($rows, $fingerprints, &$examined, &$rewritten, &$unrecomputable): void {
            foreach ($rows as $index => $row) {
                if ($row->fingerprint === null) {
                    // Never had one: a row with no date or amount is not fingerprinted at all.
                    continue;
                }

                $examined++;
                $fingerprint = $fingerprints[$index] ?? null;

                if ($fingerprint === null) {
                    // It had a fingerprint and the same inputs no longer produce one. Reported rather
                    // than left half-written — this is the number that says an old key is still needed.
                    $unrecomputable++;

                    continue;
                }

                if ($fingerprint === $row->fingerprint) {
                    continue;
                }

                $this->rewrite($row, $fingerprint);
                $rewritten++;
            }
        });

        return [$examined, $rewritten, $unrecomputable];
    }

    /**
     * The staged row and, where the row was committed, the transaction that carries the same value.
     *
     * `saveQuietly()` and a bare `update()`: re-keying is not an edit of either record, and an
     * observer or a bumped timestamp would make a key rotation look like the whole ledger having been
     * rewritten.
     */
    private function rewrite(StatementImportRow $row, string $fingerprint): void
    {
        $row->fingerprint = $fingerprint;
        $row->saveQuietly();

        if ($row->transaction_id === null) {
            return;
        }

        FinancialTransaction::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($row->transaction_id)
            ->update(['external_id_blind_index' => $fingerprint]);
    }
}
