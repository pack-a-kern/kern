<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Models;

use App\Domain\Declarations\Enums\DeclarationKind;
use App\Domain\Declarations\Enums\DeclarationStatus;
use App\Domain\Declarations\Exceptions\DeclarationIsImmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Tenancy\ScopedToTenant;

/**
 * `declarations` (`BLUEPRINT.md` §6.10, §12.3) — see that migration's docblock. Carries no
 * ciphertext of its own: `snapshot_json` is ids, hashes, and RON minor-unit totals already public
 * within the tenant boundary `ScopedToTenant` enforces, not raw PII.
 *
 * **Immutability enforcement (`docs/AGENTS.md` rule 18) lives here, in code, not only in the
 * action layer**: once a row has been persisted with `locked_at` set (`SUBMITTED`), any further
 * `save()` is rejected unless it is *exactly* the single `SUBMITTED -> ACCEPTED|REJECTED` status
 * flip `App\Domain\Declarations\Services\DeclarationLifecycle` performs — every other column, and
 * every other status transition, throws `DeclarationIsImmutableException`. This is defense in
 * depth: even a future caller that bypasses `DeclarationLifecycle` entirely and calls
 * `$declaration->save()` directly cannot mutate a filed declaration.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $form_version_id
 * @property int $fiscal_year
 * @property DeclarationKind $kind
 * @property DeclarationStatus $status
 * @property string $assessment_id
 * @property string|null $snapshot_hash
 * @property array<string, mixed>|null $snapshot_json
 * @property string|null $generated_object_path
 * @property string|null $supersedes_declaration_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $locked_at
 */
final class Declaration extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    public const ?string UPDATED_AT = null;

    /** @var array<string, string> */
    protected $attributes = [
        'kind' => 'ORIGINAL',
        'status' => 'DRAFT',
    ];

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'form_version_id',
        'fiscal_year',
        'kind',
        'status',
        'assessment_id',
        'snapshot_hash',
        'snapshot_json',
        'generated_object_path',
        'supersedes_declaration_id',
        'locked_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'fiscal_year' => 'integer',
        'kind' => DeclarationKind::class,
        'status' => DeclarationStatus::class,
        'snapshot_json' => 'array',
        'locked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $declaration): void {
            if (! $declaration->exists) {
                return;
            }

            // `getOriginal()` re-applies attribute casts (constructing a fresh model instance
            // from the raw original attributes) — comparing its enum-cast `status` against a raw
            // string with `===` would always be false, and its `locked_at` would come back as a
            // `Carbon` instance rather than the raw column value. `getRawOriginal()` returns the
            // uncast database value, matching `getDirty()`'s own uncast values below exactly.
            if ($declaration->getRawOriginal('locked_at') === null) {
                return;
            }

            $dirty = $declaration->getDirty();

            if ($dirty === []) {
                return;
            }

            $isSoleStatusFlip = array_keys($dirty) === ['status']
                && $declaration->getRawOriginal('status') === DeclarationStatus::SUBMITTED->value
                && in_array(
                    $dirty['status'],
                    [DeclarationStatus::ACCEPTED->value, DeclarationStatus::REJECTED->value],
                    true,
                );

            if (! $isSoleStatusFlip) {
                throw DeclarationIsImmutableException::forDeclaration($declaration->id);
            }
        });
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return BelongsTo<Declaration, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_declaration_id');
    }

    /** @return HasMany<DeclarationSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(DeclarationSubmission::class, 'declaration_id');
    }
}
