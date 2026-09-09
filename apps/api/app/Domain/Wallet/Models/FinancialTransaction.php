<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Enums\TransactionClassificationStatus;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * `financial_transactions` (`BLUEPRINT.md` §6.3). See that migration's docblock for the
 * no-`updated_at` decision. Same encrypted-field/tenancy-trait interaction as
 * `FinancialAccount` — `tax_subject_id` must be passed explicitly at construction.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $account_id
 * @property \Illuminate\Support\Carbon $booked_at
 * @property \Illuminate\Support\Carbon|null $value_at
 * @property int $amount_minor
 * @property string $currency_code
 * @property string $description_ciphertext
 * @property string $counterparty_ciphertext
 * @property string|null $external_id_ciphertext
 * @property string|null $external_id_blind_index
 * @property string|null $import_batch_id
 * @property TransactionClassificationStatus $classification_status
 * @property RecordSource $source
 */
final class FinancialTransaction extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'account_id',
        'booked_at',
        'value_at',
        'amount_minor',
        'currency_code',
        'description_ciphertext',
        'counterparty_ciphertext',
        'external_id_ciphertext',
        'external_id_blind_index',
        'import_batch_id',
        'classification_status',
        'source',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'booked_at' => 'date',
        'value_at' => 'date',
        'classification_status' => TransactionClassificationStatus::class,
        'source' => RecordSource::class,
        'description_ciphertext' => Encrypted::class,
        'counterparty_ciphertext' => Encrypted::class,
        'external_id_ciphertext' => Encrypted::class,
    ];

    /** @return BelongsTo<FinancialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    /** @return HasMany<TransactionSplit, $this> */
    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class, 'transaction_id');
    }

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('tax_subject_id');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('tax_subject_id must be a non-empty string.');
        }

        return $value;
    }
}
