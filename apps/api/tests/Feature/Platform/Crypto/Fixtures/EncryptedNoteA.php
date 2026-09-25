<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Crypto\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use RuntimeException;

/**
 * Test fixture standing in for a future real model (e.g. `PfProfile`) that encrypts fields via
 * `Casts\Encrypted`. Two near-identical fixture classes/tables (`EncryptedNoteA`/`EncryptedNoteB`)
 * exist so tests can prove ciphertext moved to a *different table* fails to decrypt — something
 * a single fixture class cannot exercise.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string|null $field_one
 * @property string|null $field_two
 */
final class EncryptedNoteA extends Model implements EncryptsWithSubjectDek
{
    use HasUuidPrimaryKey;
    use FillsSubjectForeignKeyFirst;

    public $table = 'encrypted_notes_a';

    public $timestamps = false;

    protected $fillable = ['tax_subject_id', 'field_one', 'field_two'];

    protected $casts = [
        'field_one' => Encrypted::class,
        'field_two' => Encrypted::class,
    ];

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('tax_subject_id');

        if (! is_string($value)) {
            throw new RuntimeException('tax_subject_id must be a string.');
        }

        return $value;
    }
}
