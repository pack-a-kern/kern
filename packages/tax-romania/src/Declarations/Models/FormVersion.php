<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\TaxRomania\Database\Factories\FormVersionFactory;
use Kern\TaxRomania\Declarations\Enums\FormVersionStatus;

/**
 * `form_versions` (`BLUEPRINT.md` §6.10, §12.2) — see that migration's docblock for `schema_json`,
 * `status`, and why `instructions_document_version_id` carries no DB-level foreign key.
 *
 * @property string $id
 * @property string $form_definition_id
 * @property string $version_code
 * @property \Illuminate\Support\Carbon $applicable_from
 * @property \Illuminate\Support\Carbon|null $applicable_until
 * @property int|null $tax_year_from
 * @property int|null $tax_year_to
 * @property string|null $schema_object_path
 * @property string|null $instructions_document_version_id
 * @property array<string, mixed>|null $schema_json
 * @property FormVersionStatus $status
 */
#[UseFactory(FormVersionFactory::class)]
final class FormVersion extends Model
{
    /** @use HasFactory<FormVersionFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var array<string, string> */
    protected $attributes = [
        'status' => 'DRAFT',
    ];

    /** @var list<string> */
    protected $fillable = [
        'form_definition_id',
        'version_code',
        'applicable_from',
        'applicable_until',
        'tax_year_from',
        'tax_year_to',
        'schema_object_path',
        'instructions_document_version_id',
        'schema_json',
        'status',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'applicable_from' => 'date',
        'applicable_until' => 'date',
        'tax_year_from' => 'integer',
        'tax_year_to' => 'integer',
        'schema_json' => 'array',
        'status' => FormVersionStatus::class,
    ];

    /** @return BelongsTo<FormDefinition, $this> */
    public function formDefinition(): BelongsTo
    {
        return $this->belongsTo(FormDefinition::class, 'form_definition_id');
    }

    /**
     * Whether `$fiscalYear` falls inside this version's declared reporting range. Returns `true`
     * when neither bound is set — a version with no declared tax-year applicability data at all
     * makes no claim to check against (`docs/AGENTS.md` §2: never fabricate a constraint that
     * was not actually stated).
     */
    public function appliesToFiscalYear(int $fiscalYear): bool
    {
        if ($this->tax_year_from !== null && $fiscalYear < $this->tax_year_from) {
            return false;
        }

        if ($this->tax_year_to !== null && $fiscalYear > $this->tax_year_to) {
            return false;
        }

        return true;
    }
}
