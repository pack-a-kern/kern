<?php

declare(strict_types=1);

namespace App\Domain\Spv\Models;

use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use App\Support\Tenancy\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * `spv_messages` (`BLUEPRINT.md` §6.11) — see this table's migration docblock for the justified
 * `external_id_ciphertext` addition. `document_id` is a schema-level foreign key to
 * `Domain/Documents`'s `documents` table only — no `BelongsTo` relation here (`docs/AGENTS.md`
 * §6, the identical `rental_contracts.source_document_id` precedent).
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $external_id_ciphertext
 * @property string $external_id_blind_index
 * @property string $title_ciphertext
 * @property \Illuminate\Support\Carbon $received_at
 * @property string|null $metadata_ciphertext
 * @property string|null $document_id
 */
final class SpvMessage extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    protected $table = 'spv_messages';

    protected $fillable = [
        'tax_subject_id',
        'external_id_ciphertext',
        'external_id_blind_index',
        'title_ciphertext',
        'received_at',
        'metadata_ciphertext',
        'document_id',
    ];

    protected $casts = [
        'external_id_ciphertext' => Encrypted::class,
        'title_ciphertext' => Encrypted::class,
        'metadata_ciphertext' => Encrypted::class,
        'received_at' => 'datetime',
    ];

    public function encryptionSubjectId(): string
    {
        return $this->tax_subject_id;
    }
}
