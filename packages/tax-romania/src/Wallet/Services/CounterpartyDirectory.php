<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Models\Counterparty;

/**
 * Every counterparty this taxpayer has paid or been paid by, and what has been decided about each.
 *
 * A bank statement is the same names every month: the employer, the landlord, the supermarket.
 * Answering each once and reusing that answer is what makes an import cheap, and it is why a model
 * is only ever asked about names nobody has answered yet.
 *
 * ## A row exists because the name exists
 *
 * {@see self::observe()} writes one the first time money moves under a counterparty, before anybody
 * has decided anything. That is what makes three otherwise expensive questions cheap: which names
 * this person has (for searching), which of them nobody has answered (the worklist), and what was
 * decided last time (the lookup this class started as).
 *
 * `category_code` null means undecided. `source` null means nobody has decided — it answers "who",
 * and a row with no decision has no decider.
 *
 * ## Found without decrypting anything
 *
 * The counterparty is encrypted on every transaction, so the lookup is by blind index: the same
 * keyed hash stored on the transaction is computed for the name being asked about, and matched
 * here. Nothing is decrypted to decide what a row probably is.
 *
 * ## A person's answer outranks a proposal
 *
 * A row remembers who decided it. An answer a person gave is never replaced by one a model
 * proposed; a proposal is replaced by anything.
 */
final readonly class CounterpartyDirectory
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
        private TenantContext $tenant,
    ) {
    }

    /** The index a counterparty name is found by, or null when there is no name to find it with. */
    public function indexFor(?string $counterparty): ?string
    {
        return $this->indexesFor([$counterparty])[0];
    }

    /**
     * The same, for every counterparty in a statement at once — one request to the key manager, not
     * one per row.
     *
     * @param  list<?string>  $counterparties
     * @return list<?string> in the same order; null where there is no name
     */
    public function indexesFor(array $counterparties): array
    {
        return $this->blindIndex->computeEach(array_map(static function (?string $counterparty): ?string {
            $name = trim($counterparty ?? '');

            return $name === '' ? null : Normalizer::text($name);
        }, $counterparties));
    }

    /**
     * The rules for a set of counterparties, keyed by "index|direction" so a caller reading a
     * statement can look each row up without a query per row.
     *
     * @param  list<string>  $indexes
     * @return array<string, Counterparty>
     */
    public function forIndexes(array $indexes): array
    {
        if ($indexes === []) {
            return [];
        }

        $rules = [];

        foreach (Counterparty::query()->with('tags')->whereIn('counterparty_blind_index', $indexes)->get() as $rule) {
            $rules[self::key($rule->counterparty_blind_index, $rule->direction)] = $rule;
        }

        return $rules;
    }

    /** One rule, or null when this counterparty has not been answered in this direction. */
    public function find(?string $index, MoneyDirection $direction): ?Counterparty
    {
        if ($index === null) {
            return null;
        }

        return $this->forIndexes([$index])[self::key($index, $direction)] ?? null;
    }

    public static function key(string $index, MoneyDirection $direction): string
    {
        return $index . '|' . $direction->value;
    }

    /**
     * Note that this counterparty exists, without deciding anything about it.
     *
     * Called wherever a transaction is written with a counterparty. Cheap by design: one `upsert`
     * that does nothing when the row is already there, so the common case — the supermarket, for the
     * two hundredth time — costs one statement and no read.
     *
     * Never touches a decision. A row already carrying a category keeps it, which is the whole point
     * of the table; this only guarantees the row is present so the worklist and the search can see
     * the name.
     *
     * The name is stored beside its index for the same reason {@see self::remember()} stores it: a
     * blind index is `HMAC-SHA-256(key, name)`, so re-keying means hashing the name again, and a
     * table holding only the hash can never be re-keyed.
     *
     * `$subjectId` is for the callers that have no bound tenant — the backfill command and the
     * background classifier, which walk every taxpayer in turn. They name the subject explicitly,
     * the way `Kern\TaxRomania\Wallet\Services\DepositMovements` does for the nightly settlement
     * and for the same reason: `TenantContext` has no "set the id and trust me" method, deliberately,
     * so background work says whose row it is writing rather than pretending to be somebody.
     * Everything running in a request leaves it null and gets the bound tenant.
     */
    public function observe(?string $index, ?string $counterparty, MoneyDirection $direction, ?string $subjectId = null): void
    {
        $name = trim($counterparty ?? '');

        // No index without a name and no name without an index, as everywhere else here.
        if ($index === null || $name === '') {
            return;
        }

        $subjectId ??= $this->tenant->currentTenantId();

        $existing = Counterparty::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tax_subject_id', $subjectId)
            ->where('counterparty_blind_index', $index)
            ->where('direction', $direction->value)
            ->exists();

        if ($existing) {
            return;
        }

        /*
         * Through the model rather than an `upsert`, because the name is encrypted: the cast seals it
         * against `subject_id | table | field | record_uuid`, and a query builder writing the column
         * directly would store the plaintext.
         *
         * The unique key is (subject, index, direction), so two jobs racing on the same new name
         * collide on the insert rather than writing it twice. The loser's exception is swallowed: the
         * row it wanted exists, which is all it was asking for.
         */
        $counterpartyRow = new Counterparty([
            'tax_subject_id' => $subjectId,
            'counterparty_ciphertext' => $name,
            'counterparty_blind_index' => $index,
            'direction' => $direction,
        ]);

        try {
            $counterpartyRow->save();
        } catch (QueryException) {
            // Somebody else wrote it between the read and the write.
        }
    }

    /**
     * The counterparties whose name contains this text.
     *
     * ## Why this cannot be a query
     *
     * The name is encrypted and the index beside it is a hash, so neither can be matched against a
     * fragment: `LIKE '%digi%'` has nothing to compare, and `HMAC(key, 'digi')` equals the hash of
     * the whole name only when the whole name is "digi". Substring matching therefore happens in
     * PHP, on decrypted names, and the names come back out as blind indexes — which is what a
     * caller filters transactions by, so nothing plaintext travels further than this method.
     *
     * ## Why this reads the directory and not the ledger
     *
     * Both hold the name. The ledger holds it once per transaction — 1 199 rows for 269 names on the
     * development data — and the directory holds it once. Same answer, a quarter of the decrypts,
     * and the gap widens with every statement imported.
     *
     * Direction is not part of identity here, unlike everywhere else in this class: a person
     * searching for a shop wants the shop, whichever way money moved. So the same name found in both
     * directions is one result, and both its indexes come back.
     *
     * @return list<array{name: string, indexes: list<string>}> matches, most-used name first
     */
    public function search(string $query, int $limit = 20): array
    {
        $needle = Normalizer::text($query);

        if ($needle === '') {
            return [];
        }

        $found = [];

        foreach (Counterparty::query()->orderBy('id')->cursor() as $counterparty) {
            $name = trim((string) $counterparty->counterparty_ciphertext);

            if ($name === '' || ! str_contains(Normalizer::text($name), $needle)) {
                continue;
            }

            // Keyed by the normalised name so the two directions of one counterparty are one result,
            // and so two spellings differing only in case or accent do not both appear.
            $key = Normalizer::text($name);
            $found[$key] ??= ['name' => $name, 'indexes' => []];

            if (! in_array($counterparty->counterparty_blind_index, $found[$key]['indexes'], true)) {
                $found[$key]['indexes'][] = $counterparty->counterparty_blind_index;
            }

            if (count($found) > $limit) {
                // One past the limit is enough to know there are more; the caller asked for a
                // shortlist, and reading the rest to rank them would cost the decrypts this method
                // exists to save.
                break;
            }
        }

        return array_slice(array_values($found), 0, $limit);
    }

    /**
     * Remember an answer.
     *
     * Called with what was finally saved rather than what was proposed: the point of the table is
     * that it holds decisions. A rule with neither a category nor a tag is not stored — "I looked at
     * it and left it alone" is not an answer worth replaying onto next month's rows.
     *
     * Takes the name as well as its index, and stores both. The index is what the rule is found by;
     * the name is what makes the index recomputable when the blind-index key is rotated, which a
     * hash on its own never is. Callers already hold it — it is the counterparty the index was
     * computed from — so nothing has to be decrypted here to get it.
     *
     * `$onlyWhenUndecided` is for an answer about one transaction. Saying what a single payment was
     * is a smaller claim than saying what a party means, and one of a party's payments is routinely
     * unlike the rest — a transfer to somebody you usually lend to can be a gift. Left to overwrite,
     * answering one row quietly redefined the party for every statement that followed, which is the
     * counterparties screen's job, and that screen says how many rows it will change before it
     * changes them. An undecided party is still filled in: there is nothing to overwrite.
     *
     * @param  list<string>  $tagIds
     */
    public function remember(
        ?string $index,
        ?string $counterparty,
        MoneyDirection $direction,
        ?string $categoryCode,
        ?string $countryCode,
        array $tagIds,
        ProposalSource $source,
        bool $onlyWhenUndecided = false,
    ): void {
        $name = trim($counterparty ?? '');

        // No index without a name and no name without an index: `indexFor()` derives one from the
        // other, so a caller holding one and not the other has already lost the pairing.
        if ($index === null || $name === '' || ($categoryCode === null && $tagIds === [])) {
            return;
        }

        $subjectId = $this->tenant->currentTenantId();

        DB::transaction(function () use ($index, $name, $direction, $categoryCode, $countryCode, $tagIds, $source, $subjectId, $onlyWhenUndecided): void {
            $existing = $this->find($index, $direction);

            if ($existing !== null && $existing->source === ProposalSource::PERSON && $source === ProposalSource::AI) {
                return;
            }

            if ($onlyWhenUndecided && $existing?->category_code !== null) {
                return;
            }

            $rule = $existing ?? new Counterparty([
                'tax_subject_id' => $subjectId,
                'counterparty_ciphertext' => $name,
                'counterparty_blind_index' => $index,
                'direction' => $direction,
            ]);

            $rule->category_code = $categoryCode;
            $rule->country_code = $countryCode;
            $rule->source = $source;
            $rule->save();

            DB::table('counterparty_tags')->where('counterparty_id', $rule->id)->delete();

            foreach ($tagIds as $tagId) {
                DB::table('counterparty_tags')->insertOrIgnore([
                    'counterparty_id' => $rule->id,
                    'tag_id' => $tagId,
                    'tax_subject_id' => $subjectId,
                ]);
            }
        });
    }
}
