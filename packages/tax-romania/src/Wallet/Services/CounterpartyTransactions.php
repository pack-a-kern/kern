<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services;

use Illuminate\Database\Eloquent\Builder;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;

/**
 * The transactions of one counterparty, on its own side of zero — and what can be done to them.
 *
 * ## Why this exists at all
 *
 * Because a remembered answer that only governs the future is half a feature. The rule was read in
 * one place — `ApplyColumnMapping`, as a statement is imported — so deciding what a party is settled
 * every row that arrived *after* the decision and none that arrived before it. A person who
 * classified one of 245 identical receipts saw one row change and assumed the other 244 would
 * follow, which is the only reasonable thing to assume: remembering an answer is pointless if the
 * ledger it describes keeps disagreeing with it.
 *
 * ## What it will not touch, unless told to
 *
 * A transaction that already has a split. That split is somebody's answer — theirs, or a model's
 * they accepted — and a rule set later does not get to overrule it by default. The counterparty's
 * own answer follows the same rule in {@see CounterpartyDirectory::remember()}.
 *
 * `$replace` lifts that, and only the parties screen passes it, after telling the person how many
 * answers it would overwrite. The distinction cannot be drawn in the data: a split records no
 * author, so nothing here can tell a model's answer from the taxpayer's. Replacing is therefore a
 * decision somebody makes in front of a number, not one this service makes on their behalf.
 *
 * Tags are added rather than replaced, for the same reason: a tag on a row is a person's.
 *
 * ## Direction
 *
 * A counterparty row is per direction, so only rows on that side are touched. Money out to a person
 * being a loan says nothing about money in from them — which is exactly the case this was written
 * for, where `OUT` was `LOAN` and `IN` was still unanswered.
 */
final readonly class CounterpartyTransactions
{
    /**
     * @param  list<string>  $tagIds
     * @param  bool  $replace  also over answers already given — see the note above
     * @return int how many transactions took the answer
     */
    public function applyAnswer(
        string $subjectId,
        Counterparty $counterparty,
        ?string $category,
        array $tagIds = [],
        bool $replace = false,
    ): int {
        if ($category === null && $tagIds === []) {
            return 0;
        }

        $touched = 0;

        foreach ($this->rowsFor($subjectId, $counterparty)->cursor() as $transaction) {
            $answered = $transaction->splits()->exists();

            if ($category !== null && $answered && ! $replace) {
                continue;
            }

            if ($category !== null) {
                if ($answered) {
                    // The whole answer goes, not just the first split: a transaction divided between
                    // two categories is one answer, and leaving half of it beside the new one would
                    // make the amounts stop adding up to the transaction.
                    $transaction->splits()->delete();
                }

                TransactionSplit::create([
                    'tax_subject_id' => $subjectId,
                    'transaction_id' => $transaction->id,
                    'amount_minor' => $transaction->amount_minor,
                    'category_code' => $category,
                    'country_code' => null,
                ]);

                $transaction->classification_status = TransactionClassificationStatus::CLASSIFIED;
                $transaction->save();
            }

            if ($tagIds !== []) {
                $transaction->tags()->syncWithoutDetaching(
                    array_fill_keys($tagIds, ['tax_subject_id' => $subjectId]),
                );
            }

            $touched++;
        }

        return $touched;
    }

    /**
     * How many of this counterparty's rows already carry an answer.
     *
     * Asked before a rule is saved, so the screen can say what replacing it would cost in the one
     * place a person can still change their mind. A split records no author, so this cannot say
     * whether those answers are the taxpayer's or a model's — which is exactly why the number is put
     * in front of them rather than acted on quietly.
     */
    public function answeredCount(string $subjectId, Counterparty $counterparty): int
    {
        return $this->rowsFor($subjectId, $counterparty)->whereHas('splits')->count();
    }

    /**
     * The distinct statement lines seen under this counterparty, newest first.
     *
     * Returns decrypted strings rather than the rows holding them, for the same boundary reason as
     * `countFor`. The caller masks them before they reach a model; masking is that module's rule,
     * and reading the column is this one's.
     *
     * @return list<string>
     */
    public function descriptionsFor(string $subjectId, Counterparty $counterparty, int $limit): array
    {
        $lines = [];

        foreach ($this->rowsFor($subjectId, $counterparty)->orderBy('id')->cursor() as $transaction) {
            $line = (string) $transaction->description_ciphertext;

            if ($line !== '' && ! in_array($line, $lines, true)) {
                $lines[] = $line;
            }

            if (count($lines) >= $limit) {
                break;
            }
        }

        return $lines;
    }

    /**
     * How many rows this counterparty has on its own side of zero.
     *
     * A count rather than the query that produces it: `StatementImport` asks this to tell a model how
     * much a party is worth asking about, and handing it an Eloquent builder would put a Wallet
     * persistence object into another module. `docs/AGENTS.md` §6 allows that crossing only through
     * application services and DTOs, and deptrac cannot see the difference — it checks which classes
     * a file names, not what shape they are.
     *
     * The direction rule — out is below zero, in is at or above it — therefore has exactly one
     * definition, which is the other reason not to let callers rebuild the query.
     */
    public function countFor(string $subjectId, Counterparty $counterparty): int
    {
        return $this->rowsFor($subjectId, $counterparty)->count();
    }

    /**
     * The scope is lifted and the subject named, because this runs from a queued job as well as from
     * a request — the job has no bound tenant and names the subject it was given.
     *
     * @return Builder<FinancialTransaction>
     */
    private function rowsFor(string $subjectId, Counterparty $counterparty): Builder
    {
        return FinancialTransaction::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tax_subject_id', $subjectId)
            ->where('counterparty_blind_index', $counterparty->counterparty_blind_index)
            ->where(
                'amount_minor',
                $counterparty->direction === MoneyDirection::OUT ? '<' : '>=',
                0,
            );
    }
}
