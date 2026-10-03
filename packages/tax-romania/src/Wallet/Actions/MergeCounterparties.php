<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\Tag;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Several spellings of one party, folded into a single name.
 *
 * `Bruma Mihaela`, `Mihaela Bruma` and `BRUMA ZINA` are three parties to the schema and one person
 * to the taxpayer. Each carries its own rules, so an answer given on one leaves the others
 * unanswered — 262 transactions behind three spellings, in the case this was written for.
 *
 * ## Why every transaction is rewritten one at a time
 *
 * A ciphertext is sealed with `subject_id | table | field | record_uuid`, so it belongs to the row
 * it sits in. One `UPDATE … SET counterparty_ciphertext = <blob>` across five hundred rows would
 * write a blob carrying the wrong row's id into four hundred and ninety-nine of them, and every one
 * would throw on the next read. So each row seals its own copy of the surviving name.
 *
 * The blind index is the opposite — `HMAC(key, name)` has no row in it — but it is written in the
 * same pass because a row whose ciphertext says one thing and whose index says another is a row the
 * search and the ledger disagree about.
 *
 * Cost is in the round trips, not the cryptography: sealing a short string is about 0.03ms, so five
 * hundred rows is some 15ms of it. That is why this runs inline rather than on a queue.
 *
 * ## What is not reversible
 *
 * The losing spellings are gone. A statement naming one of them again creates it afresh, and it has
 * to be merged again — deliberately, because the alternative is a table of aliases that has to be
 * consulted on every import and kept correct forever. The taxpayer merges a handful of parties a
 * year; the import path runs on every file.
 */
final readonly class MergeCounterparties
{
    public function __construct(
        private CounterpartyDirectory $directory,
        private WalletAudit $audit,
    ) {
    }

    /**
     * @param  non-empty-list<Counterparty>  $parties  every rule row of every spelling being folded
     * @return int how many transactions were rewritten
     */
    public function __invoke(array $parties, string $name, ?string $actorId = null): int
    {
        $subjectId = $parties[0]->tax_subject_id;
        $target = $this->directory->indexFor($name);

        if ($target === null) {
            return 0;
        }

        $losing = array_values(array_unique(array_filter(
            array_map(static fn (Counterparty $party): string => $party->counterparty_blind_index, $parties),
            static fn (string $index): bool => $index !== $target,
        )));

        $rewritten = $losing === [] ? 0 : $this->rewrite($subjectId, $losing, $name, $target);

        DB::transaction(function () use ($parties, $name, $target, $subjectId): void {
            foreach ($parties as $party) {
                if ($party->counterparty_blind_index === $target) {
                    continue;
                }

                // The surviving row for this direction, which may be one of the losers renamed or a
                // row that already answered under the target name. `remember` settles that: it keeps
                // a person's answer over a model's and refuses to overwrite one that exists.
                // First that the party exists at all. `remember` keeps an answer and declines to
                // write a row when there is none to keep — so merging three spellings nobody had
                // decided yet left the survivor with no directory row, and a party with
                // transactions and no row is invisible on the screen that lists them and
                // impossible to answer afterwards.
                $this->directory->observe($target, $name, $party->direction, $subjectId);

                $this->directory->remember(
                    index: $target,
                    counterparty: $name,
                    direction: $party->direction,
                    categoryCode: $party->category_code,
                    countryCode: $party->country_code,
                    tagIds: array_values(array_map(
                        static fn (Tag $tag): string => $tag->id,
                        $party->tags()->get()->all(),
                    )),
                    source: $party->source ?? ProposalSource::PERSON,
                );

                $party->delete();
            }
        });

        $this->audit->record(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: WalletAuditEvent::COUNTERPARTIES_MERGED->value,
            resourceType: WalletAuditEvent::COUNTERPARTIES_MERGED->resourceType(),
            resourceId: $target,
            // Counts and how many spellings, never the names: the trail is read more widely than
            // the ledger it describes.
            payload: ['spellings_merged' => count($losing), 'transactions_updated' => $rewritten],
        );

        return $rewritten;
    }

    /**
     * Every transaction of the losing spellings, re-sealed under the surviving name.
     *
     * @param  list<string>  $losing
     */
    private function rewrite(string $subjectId, array $losing, string $name, string $target): int
    {
        $rewritten = 0;

        FinancialTransaction::query()
            ->where('tax_subject_id', $subjectId)
            ->whereIn('counterparty_blind_index', $losing)
            ->chunkById(200, function ($transactions) use ($name, $target, &$rewritten): void {
                foreach ($transactions as $transaction) {
                    $transaction->counterparty_ciphertext = $name;
                    $transaction->counterparty_blind_index = $target;
                    // Quietly: a merge is not an edit of the transaction, and a touched timestamp
                    // would make five hundred rows look as if somebody had changed each of them.
                    $transaction->saveQuietly();
                    $rewritten++;
                }
            });

        return $rewritten;
    }
}
