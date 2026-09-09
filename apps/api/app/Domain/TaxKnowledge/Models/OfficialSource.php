<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Domain\TaxKnowledge\Enums\TrustTier;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\OfficialSourceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `official_sources` (`BLUEPRINT.md` §6.8). Mirrors one `config/sources.php` entry — see that
 * migration's docblock for why this table is a cache of the config, not an independent source of
 * truth. Never constructed directly by application code; always through
 * {@see \App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository::resolve()}.
 *
 * @property string $id
 * @property string $code
 * @property TrustTier $trust_tier
 * @property string|null $base_url
 * @property bool $enabled
 */
#[UseFactory(OfficialSourceFactory::class)]
final class OfficialSource extends Model
{
    /** @use HasFactory<OfficialSourceFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'trust_tier',
        'base_url',
        'enabled',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'trust_tier' => TrustTier::class,
        'enabled' => 'boolean',
    ];
}
