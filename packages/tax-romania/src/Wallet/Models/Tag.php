<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\ScopedToTenant;

/**
 * `tags` — a label a person puts on their own spending, beside the tax category.
 *
 * The name is encrypted because it is free text about a person's own money: most are "groceries",
 * and then one is "doctor Ion". Screens filter by id, never by name, so nothing has to be decrypted
 * to use a tag; `name_blind_index` answers the one question that is asked by name — whether this
 * taxpayer already has a tag called this.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $name_ciphertext
 * @property string $name_blind_index
 */
final class Tag extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    protected $table = 'tags';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'name_ciphertext',
        'name_blind_index',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'name_ciphertext' => Encrypted::class,
    ];

    /** @return BelongsToMany<FinancialTransaction, $this> */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(FinancialTransaction::class, 'transaction_tags', 'tag_id', 'transaction_id');
    }

    public function encryptionSubjectId(): string
    {
        return $this->tax_subject_id;
    }
}
