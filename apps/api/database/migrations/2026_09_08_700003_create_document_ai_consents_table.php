<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `document_ai_consents` — not one of `BLUEPRINT.md` §6.6's literal tables, added because ADR 0005
 * requires "per-subject consent, recorded, not assumed from a global setting" before any document
 * content is sent to an external AI/OCR provider, and no table anywhere in the blueprint's schema
 * carries that flag. One row per `TaxSubject`; `tax_subject_id` is both primary key and foreign
 * key, the same shape `pf_profiles` uses (`docs/handoff/011-identity-tenancy.md` decision #6) —
 * there is exactly one current consent state per subject, not a history of them.
 *
 * `consented_at`/`revoked_at` follow the same nullable-timestamp-pair convention
 * `subject_memberships.revoked_at` already established, rather than a boolean: a boolean alone
 * cannot answer "when," which an auditable consent record must be able to.
 * `App\Domain\Documents\Consent\DocumentAiConsentGate` is the only reader; `grant()`/`revoke()`
 * (same class) are the only writers.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('document_ai_consents', function (Blueprint $table): void {
            $table->uuid('tax_subject_id')->primary();
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->timestampTz('consented_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_ai_consents');
    }
};
