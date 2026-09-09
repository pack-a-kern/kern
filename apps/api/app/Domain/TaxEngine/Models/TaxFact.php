<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Domain\TaxEngine\Enums\TaxFactStatus;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Database\Factories\TaxFactFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `tax_facts` (`BLUEPRINT.md` §6.7) — see that migration's docblock for the §10.3 date columns and
 * the `fx_conversion_json` deviation from the literal `fx_rate_id`.
 *
 * Uses `App\Domain\Subjects\Tenancy\ScopedToTaxSubject` (`docs/AGENTS.md` §8 — every business
 * query scoped to `tax_subject_id`) as defence in depth, even though
 * `App\Domain\TaxEngine\Repositories\TaxFactRepository` (this module's own sanctioned read path)
 * always filters by an explicitly-supplied subject id itself rather than relying on
 * `App\Domain\Subjects\Tenancy\TenantContext` being bound — see that repository's docblock for
 * why a background assessment job cannot assume an HTTP-request-scoped `TenantContext` exists.
 *
 * Composes `HasUuidPrimaryKey` (own primary key) with `FillsSubjectForeignKeyFirst`
 * (`tax_subject_id` must be set before `metadata_ciphertext`'s `Casts\Encrypted` cast runs during
 * `fill()`) — the exact combination `App\Domain\Subjects\Tenancy\ScopedToTaxSubject`'s own
 * docblock describes for "a model with both an encrypted field and this trait".
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property int $fiscal_year
 * @property string $category_code
 * @property string|null $subtype_code
 * @property \Illuminate\Support\Carbon $recognition_date
 * @property \Illuminate\Support\Carbon|null $cash_date
 * @property \Illuminate\Support\Carbon|null $earned_at
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $distributed_at
 * @property \Illuminate\Support\Carbon|null $contract_date
 * @property \Illuminate\Support\Carbon|null $transaction_date
 * @property int $gross_amount_minor
 * @property int|null $deductible_amount_minor
 * @property int|null $withheld_tax_minor
 * @property string $currency_code
 * @property int $ron_amount_minor
 * @property int|null $deductible_amount_ron_minor
 * @property int|null $withheld_tax_ron_minor
 * @property array<string, mixed>|null $fx_conversion_json
 * @property string $source_type
 * @property string $source_id
 * @property TaxFactStatus $status
 * @property string|null $metadata_ciphertext
 * @property string|null $rule_input_version
 */
#[UseFactory(TaxFactFactory::class)]
final class TaxFact extends Model implements EncryptsWithSubjectDek
{
    /** @use HasFactory<TaxFactFactory> */
    use HasFactory;
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'fiscal_year',
        'category_code',
        'subtype_code',
        'recognition_date',
        'cash_date',
        'earned_at',
        'received_at',
        'paid_at',
        'distributed_at',
        'contract_date',
        'transaction_date',
        'gross_amount_minor',
        'deductible_amount_minor',
        'withheld_tax_minor',
        'currency_code',
        'ron_amount_minor',
        'deductible_amount_ron_minor',
        'withheld_tax_ron_minor',
        'fx_conversion_json',
        'source_type',
        'source_id',
        'status',
        'metadata_ciphertext',
        'rule_input_version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'recognition_date' => 'date:Y-m-d',
        'cash_date' => 'date:Y-m-d',
        'earned_at' => 'date:Y-m-d',
        'received_at' => 'date:Y-m-d',
        'paid_at' => 'date:Y-m-d',
        'distributed_at' => 'date:Y-m-d',
        'contract_date' => 'date:Y-m-d',
        'transaction_date' => 'date:Y-m-d',
        'fx_conversion_json' => 'array',
        'status' => TaxFactStatus::class,
        'metadata_ciphertext' => Encrypted::class,
    ];

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('tax_subject_id');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('tax_subject_id must be a non-empty string.');
        }

        return $value;
    }
}
