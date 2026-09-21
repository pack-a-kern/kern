<?php

declare(strict_types=1);

namespace App\Domain\Banks\Queries;

use App\Domain\Banks\DataTransferObjects\BankOption;
use App\Domain\Banks\Models\Bank;

/**
 * The module's read surface: banks as DTOs, so no other module reaches for the {@see Bank} model.
 */
final readonly class BankDirectory
{
    /**
     * Banks a new deposit may be opened at: the active ones, by name.
     *
     * @return list<BankOption>
     */
    public function active(): array
    {
        /** @var list<Bank> $banks */
        $banks = Bank::query()->where('active', true)->orderBy('name')->get()->all();

        return array_map(self::option(...), $banks);
    }

    /**
     * Every bank, closed ones included — for a statement or a transaction from before a merger, which
     * happened at the bank that existed then. Current banks first, then closed, each by name.
     *
     * @return list<BankOption>
     */
    public function all(): array
    {
        /** @var list<Bank> $banks */
        $banks = Bank::query()->orderByDesc('active')->orderBy('name')->get()->all();

        return array_map(self::option(...), $banks);
    }

    /** Any bank, active or not — a deposit opened years ago keeps naming the bank it was opened at. */
    public function find(string $id): ?BankOption
    {
        $bank = Bank::query()->find($id);

        return $bank === null ? null : self::option($bank);
    }

    private static function option(Bank $bank): BankOption
    {
        return new BankOption(
            id: $bank->id,
            name: $bank->name,
            code: $bank->code(),
            countryCode: $bank->country_code,
            active: $bank->active,
        );
    }
}
