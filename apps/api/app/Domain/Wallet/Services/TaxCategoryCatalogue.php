<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\DataTransferObjects\TransactionSplitInput;
use App\Domain\Wallet\Enums\CategoryDirection;
use App\Domain\Wallet\Exceptions\CategoryDirectionException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The categories a person may give a transaction, read from `config/reference/tax_categories.php`.
 *
 * One source for both halves of the job: the validator rejects a code that is not in the file, and
 * the screen offers exactly the codes it will accept. Two lists would drift, and the way that drift
 * shows up is a dropdown whose options are refused on save.
 *
 * Whether a category counts as income is carried here too, and it is a *grouping* rather than a tax
 * rule — it decides which heading a code appears under and which totals it joins, not what anyone
 * owes. What is owed comes from a versioned `TaxRuleVersion`; see the config file's own note.
 */
final readonly class TaxCategoryCatalogue
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * Every code, income first.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys([...$this->group('income'), ...$this->group('not_income')]);
    }

    /**
     * Refuses a split whose category cannot have the transaction's direction.
     *
     * In the actions rather than only the request validators: a document import reaches them with a
     * category a model proposed, and never passes through the form that filters the list.
     *
     * @param  list<TransactionSplitInput>  $splits
     */
    public function assertSplitsFit(int $amountMinor, array $splits): void
    {
        $directions = [...$this->group('income'), ...$this->group('not_income')];

        foreach ($splits as $split) {
            $direction = $directions[$split->categoryCode] ?? null;

            if ($direction !== null && ! $direction->allows($amountMinor)) {
                throw CategoryDirectionException::forCategory($split->categoryCode, $amountMinor);
            }
        }
    }

    /**
     * Refuses one category that cannot have this direction — the same rule {@see assertSplitsFit()}
     * applies to a whole transaction, for a caller that holds one code and one amount.
     */
    public function assertFitsDirection(string $categoryCode, int $amountMinor): void
    {
        $this->assertSplitsFit($amountMinor, [new TransactionSplitInput(
            amountMinor: $amountMinor,
            categoryCode: $categoryCode,
        )]);
    }

    /** Whether a category may be given to money moving this way. */
    public function fitsDirection(string $categoryCode, int $amountMinor): bool
    {
        $directions = [...$this->group('income'), ...$this->group('not_income')];
        $direction = $directions[$categoryCode] ?? null;

        return $direction === null || $direction->allows($amountMinor);
    }

    /**
     * The catalogue as a screen needs it: each code with its group, its direction, and whether it is
     * offered already chosen.
     *
     * The label is not here — it is translated in the browser, like every other enum this
     * application shows, so a category added to the config file appears immediately and a new
     * language costs no server change.
     *
     * @return list<array{code: string, income: bool, direction: string, preselected: bool}>
     */
    public function toArray(): array
    {
        $preselected = $this->config->get('reference.tax_categories.preselected');
        $preselected = is_array($preselected) ? $preselected : [];
        $entries = [];

        foreach (['income' => true, 'not_income' => false] as $group => $income) {
            foreach ($this->group($group) as $code => $direction) {
                $entries[] = [
                    'code' => $code,
                    'income' => $income,
                    'direction' => $direction->value,
                    'preselected' => in_array($code, $preselected, true),
                ];
            }
        }

        return $entries;
    }

    /**
     * One group's codes with their directions. An entry whose direction is not a known one is left
     * out, so a typo in the file removes a category instead of letting it through unchecked.
     *
     * @return array<string, CategoryDirection>
     */
    private function group(string $key): array
    {
        $listed = $this->config->get("reference.tax_categories.{$key}");
        $codes = [];

        foreach (is_array($listed) ? $listed : [] as $code => $direction) {
            $known = is_string($direction) ? CategoryDirection::tryFrom($direction) : null;

            if (is_string($code) && $known !== null) {
                $codes[$code] = $known;
            }
        }

        return $codes;
    }
}
