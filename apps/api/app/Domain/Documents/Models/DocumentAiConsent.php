<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `document_ai_consents` — one row per `TaxSubject`, keyed by that subject's own id (same shape
 * as `App\Domain\Subjects\Models\PfProfile`, `docs/handoff/011-identity-tenancy.md` decision #6).
 * No encrypted column: consent timestamps carry no PII.
 *
 * Read and written only through `App\Domain\Documents\Consent\DocumentAiConsentGate` — never
 * queried directly by pipeline code, so the gate stays the single place ADR 0005's "recorded, not
 * assumed" consent rule is enforced.
 *
 * @property string $tax_subject_id
 * @property \Illuminate\Support\Carbon|null $consented_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class DocumentAiConsent extends Model
{
    protected $primaryKey = 'tax_subject_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'consented_at',
        'revoked_at',
    ];

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
