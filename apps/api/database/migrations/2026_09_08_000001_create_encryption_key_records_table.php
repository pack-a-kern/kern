<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the lifecycle of the two key classes ADR 0002 keeps deliberately distinct — the KEK(s)
 * held in OpenBao's transit engine, and the single global blind-index key — for auditability and
 * rotation bookkeeping (BLUEPRINT.md §6.1). This table never stores key material: the KEK lives in
 * OpenBao, the blind-index key lives in `config/crypto.php`/env (`CRYPTO_BLIND_INDEX_KEYS`). Each
 * row here is a record that a given (class, identifier, version) tuple exists and its rotation
 * status — the "which version is active right now" bookkeeping a rotation runbook needs.
 *
 * Per-`TaxSubject` DEKs are NOT tracked here: they live on `tax_subjects.encrypted_dek` +
 * `tax_subjects.dek_kek_version` (BLUEPRINT.md §6.2, owned by the Identity/Subjects module) — one
 * row per subject there would make this table grow unboundedly with the tenant count, which
 * defeats its purpose as a small, human-reviewable key inventory.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('encryption_key_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->enum('key_class', ['KEK', 'BLIND_INDEX']);
            $table->string('identifier', 128);
            $table->unsignedInteger('version');
            $table->enum('status', ['ACTIVE', 'RETIRED'])->default('ACTIVE');
            $table->timestampTz('activated_at');
            $table->timestampTz('retired_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['key_class', 'identifier', 'version']);
            $table->index(['key_class', 'identifier', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encryption_key_records');
    }
};
