<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `docs/handoff/006-audit-chain.md` "Key decisions" #6 asks the Identity/Subjects agent to add
 * `->foreign('subject_id')->references('id')->on('tax_subjects')` (and `actor_id` → `users`) once
 * those tables exist. Done here, but with `restrictOnDelete()`, not the unqualified `->foreign()`
 * that handoff's own snippet showed (Postgres's default FK action, `NO ACTION`, behaves the same
 * as `RESTRICT` for this purpose) — **not** `nullOnDelete()`/`cascadeOnDelete()`, and this needed
 * checking, not assuming:
 *
 * `App\Domain\Audit\Support\AuditEventHasher::payload()` includes `subject_id` and `actor_id` in
 * the exact field set that `event_hash` is computed over (read directly before writing this
 * migration, not assumed). `audit_events` also carries a `BEFORE UPDATE OR DELETE` trigger that
 * raises on *any* mutation (`docs/handoff/006-audit-chain.md` "Append-only enforcement"). A
 * `SET NULL` foreign-key action is itself an `UPDATE` executed by Postgres against the referencing
 * row — so `nullOnDelete()` here would not quietly null the column, it would make the trigger
 * raise `audit_events is append-only: UPDATE is not permitted` and abort the entire deleting
 * transaction the moment any audit event exists for that subject/actor. And even if the trigger
 * did not exist, silently nulling `subject_id`/`actor_id` on an already-hashed row would make
 * `ChainVerifier::verify()` recompute a different `event_hash` than the one stored, reporting a
 * false tamper detection on every such row. `cascadeOnDelete()` is worse: it would delete
 * append-only evidentiary rows outright.
 *
 * `restrictOnDelete()` is therefore the only correct choice, and it is also the semantically right
 * one: `BLUEPRINT.md` §25 already describes account deletion as crypto-shredding (destroy the
 * wrapped DEK; the row and its ciphertext become permanently unrecoverable garbage) plus a status
 * change, not a raw `DELETE FROM tax_subjects`/`users` — see `App\Domain\Subjects\Models\
 * TaxSubject`/`App\Domain\Identity\Models\User`'s `status` enums. An actual SQL-level row delete
 * of a `TaxSubject`/`User` that still has audit history is exactly the case this constraint should
 * block, not silently work around. `audit_chain_heads` gets the same treatment for consistency,
 * even though it carries no append-only trigger of its own (nothing here depends on that fact, in
 * case a future migration adds one).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->foreign('subject_id')->references('id')->on('tax_subjects')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('audit_chain_heads', function (Blueprint $table): void {
            $table->foreign('subject_id')->references('id')->on('tax_subjects')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_chain_heads', function (Blueprint $table): void {
            $table->dropForeign(['subject_id']);
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['actor_id']);
        });
    }
};
