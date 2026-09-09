<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spv_messages` (`BLUEPRINT.md` §6.11) — an inbox entry synced from ANAF, through either transport.
 *
 * **Justified addition beyond the blueprint's literal column list**: `external_id_ciphertext`.
 * `BLUEPRINT.md` §6.11 lists only `external_id_blind_index` for this table, but ANAF's documented
 * `descarcare?id=<id>` call (`docs/reference/anaf-spv-integration.md`) needs the real message id
 * to ever download that message again — a blind index alone cannot be reversed. Storing the
 * ciphertext alongside the blind index is the identical `financial_transactions.external_id_*`
 * pair this codebase already establishes for exact-lookup-capable encrypted fields (`BLUEPRINT.md`
 * §4.4/§6.3), not a new pattern.
 *
 * `document_id` references `Domain/Documents`'s `documents` table at the schema level only — a
 * real foreign key, never an Eloquent model import (`docs/AGENTS.md` §6; the identical precedent
 * `rental_contracts.source_document_id -> documents` already establishes). `nullOnDelete()`: a
 * downloaded message's PDF may not exist yet, or may later be deleted, without losing the inbox
 * record itself.
 *
 * Unique on `(tax_subject_id, external_id_blind_index)` makes an inbox sync idempotent — the same
 * ANAF message listed twice across two sync runs upserts one row, never a duplicate.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('spv_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();

            $table->text('external_id_ciphertext');
            $table->string('external_id_blind_index');

            $table->text('title_ciphertext');
            $table->timestampTz('received_at');
            $table->text('metadata_ciphertext')->nullable();

            $table->uuid('document_id')->nullable();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->unique(['tax_subject_id', 'external_id_blind_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spv_messages');
    }
};
