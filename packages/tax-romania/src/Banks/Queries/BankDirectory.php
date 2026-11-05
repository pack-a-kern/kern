<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Queries;

use Kern\TaxRomania\Banks\DataTransferObjects\BankOption;
use Kern\TaxRomania\Banks\Enums\BankKind;
use Kern\TaxRomania\Banks\Logos\BankLogos;
use Kern\TaxRomania\Banks\Models\Bank;

/**
 * The module's read surface: banks as DTOs, so no other module reaches for the {@see Bank} model.
 */
final readonly class BankDirectory
{
    public function __construct(private BankLogos $logos)
    {
    }

    /**
     * Banks a new deposit may be opened at: the active ones, by name.
     *
     * @param  list<BankKind>  $kinds  which kinds to keep; empty keeps every kind
     * @return list<BankOption>
     */
    public function active(array $kinds = []): array
    {
        /** @var list<Bank> $banks */
        $banks = Bank::query()->where('active', true)->orderBy('name')->get()->all();

        return array_map($this->option(...), self::ofKind($banks, $kinds));
    }

    /**
     * Every bank, closed ones included — for a statement or a transaction from before a merger, which
     * happened at the bank that existed then. Current banks first, then closed, each by name.
     *
     * @param  list<BankKind>  $kinds  which kinds to keep; empty keeps every kind
     * @return list<BankOption>
     */
    public function all(array $kinds = []): array
    {
        /** @var list<Bank> $banks */
        $banks = Bank::query()->orderByDesc('active')->orderBy('name')->get()->all();

        return array_map($this->option(...), self::ofKind($banks, $kinds));
    }

    /**
     * The banks with these ids, active or not, in one query — for a list that names several.
     *
     * @param  list<string>  $ids
     * @return array<string, BankOption> by id
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $options = [];

        foreach (Bank::query()->whereKey($ids)->get() as $bank) {
            $options[$bank->id] = $this->option($bank);
        }

        return $options;
    }

    /** Any bank, active or not — a deposit opened years ago keeps naming the bank it was opened at. */
    public function find(string $id): ?BankOption
    {
        $bank = Bank::query()->find($id);

        return $bank === null ? null : $this->option($bank);
    }

    /**
     * The banks of the kinds asked for, or all of them where none were.
     *
     * Filtered here rather than in the query because a bank's kind is read from its registration
     * number and is not a column — see `Models\Bank::kind()` for why it is derived. At the scale of a
     * national register that costs nothing; a table of thousands would want the code prefix in SQL
     * instead, and the config's knowledge restated there.
     *
     * An empty list means every kind rather than none: a caller that does not care about kinds should
     * not have to name them all, and a caller that wants nothing would not be calling.
     *
     * @param  list<Bank>  $banks
     * @param  list<BankKind>  $kinds
     * @return list<Bank>
     */
    private static function ofKind(array $banks, array $kinds): array
    {
        if ($kinds === []) {
            return $banks;
        }

        return array_values(array_filter($banks, static fn (Bank $bank): bool => in_array($bank->kind(), $kinds, true)));
    }

    private function option(Bank $bank): BankOption
    {
        $logo = $this->logos->for($bank->country_code, $bank->bic, $bank->lei, $bank->parent_lei);

        return new BankOption(
            id: $bank->id,
            name: $bank->name,
            code: $bank->code(),
            countryCode: $bank->country_code,
            active: $bank->active,
            group: $bank->group_name,
            // Versioned by the file's content, so a replaced logo is a new address and the old one can
            // be cached for good.
            logoUrl: $logo === null ? null : route(BankLogos::ROUTE, ['bank' => $bank->id, 'v' => $logo->fingerprint], false),
        );
    }
}
