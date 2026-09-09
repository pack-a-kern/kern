<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mutable "tip" pointer for each hash chain in `audit_events` (BLUEPRINT.md §4.8's per-chain
 * `sequence`/hash bookkeeping, made explicit as its own table so appends to different chains never
 * contend for the same lock — see `App\Domain\Audit\Services\AuditChainService`).
 *
 * `chain_key` is either a `tax_subjects.id` UUID (as text — no foreign key: the Subjects module's
 * table does not exist yet at this milestone, per this task's scope note) or the literal string
 * `"system"` for events with no subject. Unlike `audit_events`, this table is legitimately
 * mutated on every append; it carries none of the append-only trigger from the sibling migration.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audit_chain_heads', function (Blueprint $table): void {
            $table->string('chain_key')->primary();

            // No FK: Subjects module/tax_subjects table does not exist yet at this milestone.
            // Whoever adds it should add `->foreign('subject_id')->references('id')->on('tax_subjects')`
            // in a follow-up migration, per this task's handoff note.
            $table->uuid('subject_id')->nullable()->index();

            $table->unsignedBigInteger('last_sequence');
            $table->char('last_event_hash', 64);

            // Tz variant, not the bare timestamps() default: this project stores UTC explicitly
            // everywhere (docs/AGENTS.md §6), and App\Domain\Audit\Services\AuditChainService
            // always writes these two columns as CarbonImmutable::now('UTC').
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
    }
};
