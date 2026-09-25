<?php

declare(strict_types=1);

namespace App\Console\Commands\Wallet;

use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Services\CounterpartyRules;
use App\Support\Tenancy\TenantScope;
use Illuminate\Console\Command;

/**
 * Fills `financial_transactions.counterparty_blind_index` for rows recorded before it existed.
 *
 * The index is what makes "what did I say this counterparty was last time" answerable without
 * decrypting every row — see {@see CounterpartyRules}. A transaction written before the column
 * existed carries none, so it would never be recognised, and the answer a person already gave for
 * that shop would not be offered for it.
 *
 * Runs across every taxpayer, so it lifts the tenant scope deliberately and visibly: this is
 * maintenance over the whole table, not a request acting on somebody's behalf. Nothing is decrypted
 * that is not immediately re-hashed, and no counterparty is printed — the output is counts.
 *
 * Safe to run repeatedly: only rows with a counterparty and no index are touched.
 */
final class IndexCounterpartiesCommand extends Command
{
    /** Chunked so a large table is not held in memory, and a failure halfway leaves the rest to do. */
    private const int CHUNK = 200;

    protected $signature = 'kern:wallet:index-counterparties';

    protected $description = 'Backfill the counterparty blind index on transactions recorded before it existed.';

    public function handle(CounterpartyRules $rules): int
    {
        $indexed = 0;

        FinancialTransaction::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('counterparty_blind_index')
            ->whereNotNull('counterparty_ciphertext')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($transactions) use ($rules, &$indexed): void {
                foreach ($transactions as $transaction) {
                    $index = $rules->indexFor((string) $transaction->counterparty_ciphertext);

                    if ($index === null) {
                        continue;
                    }

                    $transaction->counterparty_blind_index = $index;
                    $transaction->saveQuietly();
                    $indexed++;
                }
            });

        $this->info(sprintf('%d transaction(s) indexed.', $indexed));

        return self::SUCCESS;
    }
}
