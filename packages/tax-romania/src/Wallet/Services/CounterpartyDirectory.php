<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services;

use Illuminate\Support\Facades\DB;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Models\CounterpartyRule;

/**
 * What this taxpayer already decided about a counterparty — looked up, and written down.
 *
 * A bank statement is the same names every month: the employer, the landlord, the supermarket.
 * Answering each once and reusing that answer is what makes an import cheap, and it is why the
 * model is only ever asked about names nobody has answered yet.
 *
 * ## Found without decrypting anything
 *
 * The counterparty is encrypted on every transaction, so the lookup is by blind index: the same
 * keyed hash stored on the transaction is computed for the name being asked about, and matched
 * against the rules table. Nothing is decrypted to decide what a row probably is.
 *
 * ## A person's answer outranks a proposal
 *
 * A rule remembers who decided it. An answer a person gave is never replaced by one a model
 * proposed; a proposal is replaced by anything.
 */
final readonly class CounterpartyRules
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
        private TenantContext $tenant,
    ) {
    }

    /** The index a counterparty name is found by, or null when there is no name to find it with. */
    public function indexFor(?string $counterparty): ?string
    {
        $name = trim($counterparty ?? '');

        return $name === '' ? null : $this->blindIndex->compute(Normalizer::text($name));
    }

    /**
     * The rules for a set of counterparties, keyed by "index|direction" so a caller reading a
     * statement can look each row up without a query per row.
     *
     * @param  list<string>  $indexes
     * @return array<string, CounterpartyRule>
     */
    public function forIndexes(array $indexes): array
    {
        if ($indexes === []) {
            return [];
        }

        $rules = [];

        foreach (CounterpartyRule::query()->with('tags')->whereIn('counterparty_blind_index', $indexes)->get() as $rule) {
            $rules[self::key($rule->counterparty_blind_index, $rule->direction)] = $rule;
        }

        return $rules;
    }

    /** One rule, or null when this counterparty has not been answered in this direction. */
    public function find(?string $index, MoneyDirection $direction): ?CounterpartyRule
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
    ): void {
        $name = trim($counterparty ?? '');

        // No index without a name and no name without an index: `indexFor()` derives one from the
        // other, so a caller holding one and not the other has already lost the pairing.
        if ($index === null || $name === '' || ($categoryCode === null && $tagIds === [])) {
            return;
        }

        $subjectId = $this->tenant->currentTenantId();

        DB::transaction(function () use ($index, $name, $direction, $categoryCode, $countryCode, $tagIds, $source, $subjectId): void {
            $existing = $this->find($index, $direction);

            if ($existing !== null && $existing->source === ProposalSource::PERSON && $source === ProposalSource::AI) {
                return;
            }

            $rule = $existing ?? new CounterpartyRule([
                'tax_subject_id' => $subjectId,
                'counterparty_ciphertext' => $name,
                'counterparty_blind_index' => $index,
                'direction' => $direction,
            ]);

            $rule->category_code = $categoryCode;
            $rule->country_code = $countryCode;
            $rule->source = $source;
            $rule->save();

            DB::table('counterparty_rule_tags')->where('rule_id', $rule->id)->delete();

            foreach ($tagIds as $tagId) {
                DB::table('counterparty_rule_tags')->insertOrIgnore([
                    'rule_id' => $rule->id,
                    'tag_id' => $tagId,
                    'tax_subject_id' => $subjectId,
                ]);
            }
        });
    }
}
