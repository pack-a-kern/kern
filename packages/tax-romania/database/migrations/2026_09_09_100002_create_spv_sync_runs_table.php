<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spv_sync_runs` (`BLUEPRINT.md` §6.11) — one row per inbox-sync attempt, through either
 * `Kern\TaxRomania\Spv\Contracts\SpvInboxSyncTransport` implementation. `summary_json` carries counts
 * only (`{"message_count": int}` on success, `{"error": "..."}` on failure) — never message
 * titles or any other content (`BLUEPRINT.md` §16).
 *
 * `tax_subject_id` denormalised, same precedent as `spv_sessions`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('spv_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('spv_connections')->cascadeOnDelete();

            $table->string('status');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('summary_json')->nullable();

            $table->index('tax_subject_id');
            $table->index('connection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spv_sync_runs');
    }
};
