<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Wallet;

use Illuminate\Console\Command;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;

/**
 * Puts the counterparties of transactions written before the directory existed into it.
 *
 * Every transaction already carries its counterparty and that name's blind index. What it does not
 * carry is a row in `counterparties`, because until now one appeared only when somebody decided
 * something — so a ledger of 1 500 transactions and 317 names had two rows.
 *
 * ## Why this is a command and not a migration
 *
 * The name has to be re-encrypted rather than copied. `Kern\Platform\Crypto\AssociatedData` binds
 * every ciphertext to `subject_id | table | field | record_uuid`, so the blob on the transaction
 * would not decrypt in this table — it names the other table and the other row. Re-encrypting means
 * the model layer, and a migration deliberately does not reach into that.
 *
 * ## What it does not do
 *
 * Decide anything. Every row it writes is a name with no category, no tags and no source, which is
 * exactly the worklist the background classifier then reads. Rows that already carry a decision are
 * left untouched.
 *
 * Runs across every taxpayer, so it lifts the tenant scope visibly and binds each subject in turn —
 * the directory is subject-scoped on write, and a name belongs to whoever paid it. Nothing is
 * printed but counts: the input here is a list of people's counterparties.
 *
 * Safe to run repeatedly.
 */
final class BackfillCounterpartiesCommand extends Command
{
    /** Chunked so a large table is not held in memory, and a failure halfway leaves the rest to do. */
    private const int CHUNK = 500;

    protected $signature = 'kern:counterparties:backfill';

    protected $description = 'Put the counterparties of already-recorded transactions into the directory.';

    public function handle(CounterpartyDirectory $directory): int
    {
        $seen = 0;
        $subjects = [];

        FinancialTransaction::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNotNull('counterparty_blind_index')
            ->whereNotNull('counterparty_ciphertext')
            /*
             * No ordering of its own: `chunkById` pages with `where id > :last` and supplies the
             * matching `order by id`, and an order placed ahead of that one becomes the primary sort
             * while the pagination still walks ids — which skips rows.
             */
            ->chunkById(self::CHUNK, function ($transactions) use ($directory, &$seen, &$subjects): void {
                foreach ($transactions as $transaction) {
                    $subjectId = (string) $transaction->tax_subject_id;
                    $subjects[$subjectId] = true;

                    $directory->observe(
                        (string) $transaction->counterparty_blind_index,
                        (string) $transaction->counterparty_ciphertext,
                        MoneyDirection::ofAmount((int) $transaction->amount_minor),
                        $subjectId,
                    );

                    $seen++;
                }
            });

        $this->info(sprintf('Read %d transactions across %d taxpayers.', $seen, count($subjects)));

        return self::SUCCESS;
    }
}
