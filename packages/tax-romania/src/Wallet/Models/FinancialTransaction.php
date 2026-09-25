<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
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
 * @property int $amount_minor
 * @property string $currency_code
 * @property string $description_ciphertext
 * @property string $counterparty_ciphertext
 * @property string|null $bank_id
 * @property string|null $counterparty_blind_index
 * @property string|null $import_id
 * @property string|null $external_id_ciphertext
 * @property string|null $external_id_blind_index
 * @property TransactionClassificationStatus $classification_status
 * @property RecordSource $source
 */
final class FinancialTransaction extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'account_id',
        'bank_id',
        'booked_at',
        'amount_minor',
        'currency_code',
        'description_ciphertext',
        'counterparty_ciphertext',
        'counterparty_blind_index',
        'external_id_ciphertext',
        'external_id_blind_index',
        'classification_status',
        'source',
        'document_id',
        'document_extraction_id',
        'import_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'booked_at' => 'date',
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

    /**
     * What this was for, in the person's own words — beside what it was taxed as, which is the
     * splits above.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'transaction_tags', 'transaction_id', 'tag_id');
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
