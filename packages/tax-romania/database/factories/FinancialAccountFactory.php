<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

/**
 * @extends Factory<FinancialAccount>
 *
 * Unlike `TaxSubjectFactory`, this needs no two-write dance: a `FinancialAccount`'s DEK is its
 * owning `TaxSubject`'s (already-existing) DEK, not its own, so there is no chicken-and-egg
 * problem — `tax_subject_id` still must be supplied explicitly by the caller (it references an
 * already-created `TaxSubject`), but a single `create(['tax_subject_id' => $subject->id, ...])`
 * call works correctly regardless of attribute order, thanks to
 * `Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst`.
 */
final class FinancialAccountFactory extends Factory
{
    protected $model = FinancialAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => AccountKind::GENERAL,
            'currency_code' => 'RON',
            'display_name_ciphertext' => 'Test Account ' . Str::uuid(),
            'source' => RecordSource::MANUAL,
        ];
    }
}
