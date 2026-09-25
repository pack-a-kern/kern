<?php

declare(strict_types=1);

namespace Kern\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kern\Documents\Enums\ExtractionStatus;
use Kern\Documents\Enums\ExtractorType;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use RuntimeException;

/**
 * `document_extractions` (`BLUEPRINT.md` §6.6, §14.6) — one row per extraction/AI interpretation
 * attempt. There is no `tax_subject_id` column (the blueprint's own schema does not carry one on
 * this table), yet `result_ciphertext` must still be encrypted under the owning subject's DEK
 * (§4.3 — "date extrase OCR" is on the mandatory-encrypt list). Rather than an extra relation
 * query inside `encryptionSubjectId()` — which would need `App\Domain\Subjects\Tenancy\
 * TenantContext` bound in contexts (queue jobs) that legitimately have none — the owning
 * subject id is supplied explicitly and held in a plain, non-persisted PHP property. This is why
 * `Model::create()`/`new self([...])` is never used directly for this model: {@see self::propose()}
 * is the only sanctioned constructor, and it sets that property *before* `result_ciphertext` is
 * ever assigned.
 *
 * The same problem exists in reverse for a row loaded back through an ordinary query (`::find()`,
 * `::where(...)->get()`, a cursor paginator, `->fresh()`, ...) rather than held in the same PHP
 * object `propose()` returned: the freshly-hydrated instance has no override set, so decrypting
 * `result_ciphertext` throws until {@see self::withEncryptionSubjectId()} supplies it. A caller
 * reading extractions back (`Kern\Documents\Http\Controllers\
 * ListDocumentExtractionsController`, in particular) always loads the owning, tenant-scoped
 * `Document` first — exactly that document's `tax_subject_id` is what to pass.
 *
 * `status` is deliberately **not** in `$fillable`: mass-assignment (`fill()`/`create()`) can never
 * move a row out of `PROPOSED`. The only path that can is {@see self::review()}, which requires a
 * human reviewer id and an explicit non-`PROPOSED` decision — `BLUEPRINT.md` §14.6, "AI-extracted
 * values ... never `CONFIRMED` automat în v1." `Kern\Documents\Review\
 * {AcceptDocumentExtraction,CorrectDocumentExtraction}` are the only callers.
 *
 * @property string $id
 * @property string $document_id
 * @property ExtractorType $extractor_type
 * @property string $extractor_version
 * @property string|null $model_provider
 * @property string|null $model_name
 * @property string $schema_version
 * @property string $result_ciphertext
 * @property string|null $confidence_decimal
 * @property int|null $duration_ms
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property ExtractionStatus $status
 * @property string|null $reviewed_by
 */
final class DocumentExtraction extends Model implements EncryptsWithSubjectDek
{
    use HasUuidPrimaryKey;

    private ?string $encryptionSubjectIdOverride = null;

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'extractor_type',
        'extractor_version',
        'model_provider',
        'model_name',
        'schema_version',
        'result_ciphertext',
        'confidence_decimal',
        'duration_ms',
        'prompt_tokens',
        'completion_tokens',
        'reviewed_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'extractor_type' => ExtractorType::class,
        'status' => ExtractionStatus::class,
        'result_ciphertext' => Encrypted::class,
        'confidence_decimal' => 'string',
    ];

    /**
     * The only sanctioned way to create a `document_extractions` row.
     *
     * @param array<array-key, mixed> $result the structured extraction result — JSON-encoded,
     *                                         then encrypted, never stored as plaintext.
     * @param ?string $confidenceDecimal a decimal string in `[0, 1]`, e.g. `"0.9200"` — never a
     *                                    PHP float (`docs/CODE-QUALITY.md` §4). Null where the
     *                                    reader reported none: a parser has no opinion, and a model
     *                                    that omitted the field did not measure anything. A
     *                                    placeholder here would be drawn as a real assessment.
     */
    public static function propose(
        Document $document,
        ExtractorType $extractorType,
        string $extractorVersion,
        ?string $modelProvider,
        ?string $modelName,
        string $schemaVersion,
        array $result,
        ?string $confidenceDecimal,
        /** What the reading cost, where the reader reported it. A parser reports nothing. */
        ?int $durationMs = null,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
    ): self {
        $extraction = new self();
        $extraction->encryptionSubjectIdOverride = $document->tenantId();

        $extraction->fill([
            'document_id' => $document->id,
            'extractor_type' => $extractorType,
            'extractor_version' => $extractorVersion,
            'model_provider' => $modelProvider,
            'model_name' => $modelName,
            'schema_version' => $schemaVersion,
            'result_ciphertext' => json_encode($result, JSON_THROW_ON_ERROR),
            'confidence_decimal' => $confidenceDecimal,
            'duration_ms' => $durationMs,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ]);
        $extraction->status = ExtractionStatus::PROPOSED;
        $extraction->save();

        return $extraction;
    }

    /**
     * The only sanctioned transition out of `PROPOSED`. Not called anywhere in this task's
     * automatic pipeline — reserved for the future human-review action this milestone's own
     * lifecycle ends at ("`PROPOSED` → human review").
     */
    public function review(string $reviewerId, ExtractionStatus $decision): void
    {
        if ($decision === ExtractionStatus::PROPOSED) {
            throw new InvalidArgumentException('A review decision cannot itself be PROPOSED.');
        }

        if ($reviewerId === '') {
            throw new InvalidArgumentException('A review requires a non-empty reviewer id.');
        }

        $this->reviewed_by = $reviewerId;
        $this->status = $decision;
        $this->save();
    }

    /**
     * Supplies the subject id needed to decrypt `result_ciphertext` on an instance loaded through
     * an ordinary query rather than freshly created by {@see self::propose()} in this same
     * process — see this class's own docblock for why that override cannot simply be looked up
     * from `document_id` automatically. Must be called before any encrypted attribute is read on
     * such an instance.
     */
    public function withEncryptionSubjectId(string $subjectId): static
    {
        $this->encryptionSubjectIdOverride = $subjectId;

        return $this;
    }

    public function encryptionSubjectId(): string
    {
        return $this->encryptionSubjectIdOverride
            ?? throw new RuntimeException(
                'DocumentExtraction::propose() must be used to create a row, or withEncryptionSubjectId() '
                . 'must be called before decrypting one loaded back from the database.',
            );
    }
}
