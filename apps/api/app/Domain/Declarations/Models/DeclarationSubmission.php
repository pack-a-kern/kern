<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Models;

use App\Domain\Declarations\Enums\SubmissionTransport;
use App\Domain\Declarations\Exceptions\SubmissionResponseAlreadyRecordedException;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\TenantScope;
use RuntimeException;

/**
 * `declaration_submissions` (`BLUEPRINT.md` §6.10) — see that migration's docblock. No
 * `tax_subject_id` column (matches the blueprint's own schema); `encryptionSubjectId()` is
 * supplied explicitly by {@see self::record()} from the owning `Declaration` for a freshly
 * created row (the identical shape `Kern\Documents\Models\DocumentExtraction` establishes
 * for the same case), and falls back to looking the owning `Declaration`'s `tax_subject_id` up
 * directly for a row hydrated by a normal query (e.g. `App\Domain\Declarations\Actions\
 * RecordDeclarationSubmissionResponse`'s `DeclarationSubmission::query()->findOrFail(...)`, which
 * never goes through {@see self::record()}) — otherwise `recordResponse()`'s encrypted
 * `response_ciphertext` write would have no subject id to encrypt under. That lookup bypasses
 * `Kern\Platform\Tenancy\TenantScope` deliberately: resolving *which* DEK protects this
 * row is infrastructure, not an authorized business read of the declaration's own data, the same
 * "genuinely subject-agnostic system query" category that trait's own docblock names as its
 * sanctioned escape hatch.
 *
 * `{@see self::record()}` is the only sanctioned constructor for a *new* row — mass-assignment/
 * `create()` is never used directly, so the encryption subject override is always set before
 * `external_reference_ciphertext` is ever assigned during creation.
 *
 * @property string $id
 * @property string $declaration_id
 * @property SubmissionTransport $transport
 * @property \Illuminate\Support\Carbon $submitted_at
 * @property string|null $external_reference_ciphertext
 * @property string|null $receipt_document_id
 * @property string|null $response_ciphertext
 * @property \Illuminate\Support\Carbon $created_at
 */
final class DeclarationSubmission extends Model implements EncryptsWithSubjectDek
{
    use HasUuidPrimaryKey;

    public const ?string UPDATED_AT = null;

    private ?string $encryptionSubjectIdOverride = null;

    /** @var list<string> */
    protected $fillable = [
        'declaration_id',
        'transport',
        'submitted_at',
        'external_reference_ciphertext',
        'receipt_document_id',
        'response_ciphertext',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'transport' => SubmissionTransport::class,
        'submitted_at' => 'datetime',
        'external_reference_ciphertext' => Encrypted::class,
        'response_ciphertext' => Encrypted::class,
    ];

    public static function record(
        Declaration $declaration,
        SubmissionTransport $transport,
        DateTimeImmutable $submittedAt,
        ?string $externalReference,
    ): self {
        $submission = new self();
        $submission->encryptionSubjectIdOverride = $declaration->tax_subject_id;

        $submission->fill([
            'declaration_id' => $declaration->id,
            'transport' => $transport,
            'submitted_at' => $submittedAt,
            'external_reference_ciphertext' => $externalReference,
        ]);
        $submission->save();

        return $submission;
    }

    /**
     * Records ANAF's outcome, once. Detected via "a response field is already non-null" — a
     * genuine outcome carrying neither a response payload nor a receipt document would not be
     * caught by this check; nothing in this task's scope produces such an outcome, and the owning
     * `Declaration`'s own status guard (`SUBMITTED -> ACCEPTED|REJECTED` only ever fires once,
     * since the second attempt would find `status` already `ACCEPTED`/`REJECTED`) is the
     * authoritative guard against double-recording in the sanctioned call path
     * (`App\Domain\Declarations\Actions\RecordDeclarationSubmissionResponse`). This check is
     * defense in depth for a caller that touches this model directly.
     */
    public function recordResponse(?string $responsePayload, ?string $receiptDocumentId): void
    {
        if ($this->response_ciphertext !== null || $this->receipt_document_id !== null) {
            throw SubmissionResponseAlreadyRecordedException::forSubmission($this->id);
        }

        $this->response_ciphertext = $responsePayload;
        $this->receipt_document_id = $receiptDocumentId;
        $this->save();
    }

    /** @return BelongsTo<Declaration, $this> */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(Declaration::class, 'declaration_id');
    }

    public function encryptionSubjectId(): string
    {
        if ($this->encryptionSubjectIdOverride !== null) {
            return $this->encryptionSubjectIdOverride;
        }

        /** @var string|null $subjectId */
        $subjectId = Declaration::withoutGlobalScope(TenantScope::class)
            ->where('id', $this->declaration_id)
            ->value('tax_subject_id');

        if ($subjectId === null) {
            throw new RuntimeException(
                "Unable to resolve the owning tax subject for DeclarationSubmission {$this->id}: " .
                "declaration {$this->declaration_id} does not exist.",
            );
        }

        return $subjectId;
    }
}
