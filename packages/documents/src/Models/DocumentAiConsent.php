<?php

declare(strict_types=1);

namespace Kern\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Tenancy\Tenancy;

/**
 * `document_ai_consents` — one row per `TaxSubject`, keyed by that subject's own id (same shape
 * as `Kern\TaxRomania\Subjects\Models\PfProfile`, `docs/handoff/011-identity-tenancy.md` decision #6).
 * No encrypted column: consent timestamps carry no PII.
 *
 * Read and written only through `Kern\Documents\Consent\DocumentAiConsentGate` — never
 * queried directly by pipeline code, so the gate stays the single place ADR 0005's "recorded, not
 * assumed" consent rule is enforced.
 *
 * @property \Illuminate\Support\Carbon|null $consented_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class DocumentAiConsent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'consented_at',
        'revoked_at',
    ];

    /**
     * One row per tenant, keyed by the tenant itself — so the primary key's name is configuration,
     * which `$primaryKey` cannot be.
     */
    public function getKeyName(): string
    {
        return Tenancy::column();
    }

    /** @return list<string> */
    public function getFillable(): array
    {
        return array_values([Tenancy::column(), ...parent::getFillable()]);
    }

    /** @var array<string, string> */
    protected $casts = [
        'consented_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->consented_at !== null && $this->revoked_at === null;
    }
}
