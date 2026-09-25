<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\TaxRomania\Database\Factories\FormDefinitionFactory;

/**
 * `form_definitions` (`BLUEPRINT.md` §6.10) — see that migration's docblock. Global registry, not
 * tenant-scoped; carries no PII.
 *
 * @property string $id
 * @property string $code
 * @property string $issuer
 */
#[UseFactory(FormDefinitionFactory::class)]
final class FormDefinition extends Model
{
    /** @use HasFactory<FormDefinitionFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'issuer',
    ];

    /** @return HasMany<FormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class, 'form_definition_id');
    }
}
