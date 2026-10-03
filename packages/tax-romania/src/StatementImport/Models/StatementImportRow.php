<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\StatementImport\Enums\RowProposalSource;

/**
 * `statement_import_rows` — one line of a statement, while a person decides what to do with it.
 *
 * Encrypted exactly like the transaction it is about to become: the description and the counterparty
 * are what the bank printed about a person's own money, and staging is not a reason to hold them in
 * clear for the days before an import is confirmed.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $import_id
 * @property int $row_number
 * @property \Illuminate\Support\Carbon|null $booked_at
 * @property int|null $amount_minor
 * @property string|null $currency_code
 * @property string|null $description_ciphertext
 * @property string|null $counterparty_ciphertext
 * @property string|null $counterparty_blind_index
 * @property string|null $reference_ciphertext
 * @property string|null $fields_ciphertext every field the file named, as JSON
 * @property string|null $fingerprint
 * @property string|null $duplicate_transaction_id
 * @property string|null $category_code
 * @property string|null $country_code
 * @property string|null $tags_ciphertext
 * @property RowProposalSource|null $proposal_source
 * @property bool $included
 * @property string|null $transaction_id
 */
final class StatementImportRow extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    protected $table = 'statement_import_rows';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'import_id',
        'row_number',
        'booked_at',
        'amount_minor',
        'currency_code',
        'description_ciphertext',
        'counterparty_ciphertext',
        'counterparty_blind_index',
        'reference_ciphertext',
        'fields_ciphertext',
        'fingerprint',
        'duplicate_transaction_id',
        'category_code',
        'country_code',
        'tags_ciphertext',
        'proposal_source',
        'included',
        'transaction_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'booked_at' => 'date',
        'description_ciphertext' => Encrypted::class,
        'counterparty_ciphertext' => Encrypted::class,
        'reference_ciphertext' => Encrypted::class,
        'fields_ciphertext' => Encrypted::class,
        // Tag *names*, not ids: a tag a model proposed may not exist until the import is committed,
        // and creating one for a row nobody kept would leave the vocabulary full of rejected guesses.
        'tags_ciphertext' => Encrypted::class,
        'proposal_source' => RowProposalSource::class,
        'included' => 'boolean',
    ];

    /** @return BelongsTo<StatementImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class, 'import_id');
    }

    public function encryptionSubjectId(): string
    {
        return $this->tax_subject_id;
    }

    /**
     * The tag names on this row, as a list.
     *
     * Stored as one encrypted JSON string rather than a table, because a staged row's tags are a
     * proposal being edited, not a relation anything queries — and a pivot to tags that do not exist
     * yet would need the tags created first.
     *
     * @return list<string>
     */
    public function tagNames(): array
    {
        $raw = $this->tags_ciphertext;

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }
}
