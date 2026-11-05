<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Logos;

use Kern\TaxRomania\Banks\Enums\BankKind;
use Kern\TaxRomania\Banks\Models\Bank;

/**
 * What the settings screen's Banks section shows about logos: where they are read from, whether that
 * folder exists, how many of the banks a person picks from have one, and the file each of the rest
 * would need. Read from the folder's index — the same one the bank lists use.
 */
final readonly class LogoCoverage
{
    public function __construct(private BankLogos $logos)
    {
    }

    /**
     * @param  list<BankKind>  $kinds  the kinds a person picks from
     * @return array{path: string, exists: bool, banks: int, with_logo: int, missing: list<array{id: string, name: string, file: string|null}>}
     */
    public function of(array $kinds): array
    {
        /** @var list<Bank> $banks */
        $banks = Bank::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'bic', 'lei', 'parent_lei', 'country_code', 'ecb_code'])->all();
        $banks = array_values(array_filter($banks, static fn (Bank $bank): bool => in_array($bank->kind(), $kinds, true)));

        $missing = [];

        foreach ($banks as $bank) {
            if ($this->logos->for($bank->country_code, $bank->bic, $bank->lei, $bank->parent_lei) === null) {
                $file = BankLogos::fileFor($bank->country_code, $bank->bic, $bank->lei, $bank->parent_lei);
                $missing[] = ['id' => $bank->id, 'name' => $bank->name, 'file' => $file === null ? null : $file . '.*'];
            }
        }

        $path = $this->logos->path();
        $application = base_path() . '/';

        return [
            // Shown to the administrator, who puts the files there: relative to the application where
            // it is inside it, as configured otherwise.
            'path' => str_starts_with($path, $application) ? substr($path, strlen($application)) : $path,
            'exists' => $this->logos->available(),
            'banks' => count($banks),
            'with_logo' => count($banks) - count($missing),
            'missing' => $missing,
        ];
    }
}
