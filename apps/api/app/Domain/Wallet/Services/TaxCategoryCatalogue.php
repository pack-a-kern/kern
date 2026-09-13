<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The categories a person may give a transaction, read from `config/tax_categories.php`.
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
        return [...$this->income(), ...$this->notIncome()];
    }

    /** @return list<string> */
    public function income(): array
    {
        return $this->group('income');
    }

    /** @return list<string> */
    public function notIncome(): array
    {
        return $this->group('not_income');
    }

    public function isIncome(string $code): bool
    {
        return in_array($code, $this->income(), true);
    }

    /**
     * The catalogue as a screen needs it: each code with whether it is income.
     *
     * The label is not here — it is translated in the browser, like every other enum this
     * application shows, so a category added to the config file appears immediately and a new
     * language costs no server change.
     *
     * @return list<array{code: string, income: bool}>
     */
    public function toArray(): array
    {
        return [
            ...array_map(static fn (string $code): array => ['code' => $code, 'income' => true], $this->income()),
            ...array_map(static fn (string $code): array => ['code' => $code, 'income' => false], $this->notIncome()),
        ];
    }

    /** @return list<string> */
    private function group(string $key): array
    {
        $codes = $this->config->get("tax_categories.{$key}");

        return is_array($codes) ? array_values(array_filter($codes, is_string(...))) : [];
    }
}
