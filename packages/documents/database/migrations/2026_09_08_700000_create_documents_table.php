<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kern\Platform\Tenancy\Tenancy;

/**
 * `documents` (`BLUEPRINT.md` §6.6) — Milestone 7. One row per uploaded document. The `documents`
 * row is the *only* place the document's own object-store address, verified size/type, and
 * SHA-256 (§4.9) live; the encrypted bytes themselves never touch Postgres (`App\Support\
 * ObjectStore\EncryptedObjectStore`, `docs/handoff/012-encrypted-object-store.md`).
 *
 * `status` is the literal enum this milestone was asked to build:
 * `QUARANTINE|SCANNED|PROCESSING|READY|REJECTED`. It is deliberately *not* the only mechanism
 * that keeps an unscanned upload unreachable — see `Kern\Documents\Quarantine\
 * QuarantineObjectStore`'s docblock for why quarantine content lives on a physically separate
 * disk the normal read path never queries, so a forgotten status check cannot leak it.
 *
 * `tax_subject_id` uses `cascadeOnDelete()`, unlike the `restrictOnDelete()` `docs/handoff/
 * 011-identity-tenancy.md` chose for `audit_events`/`audit_chain_heads`. That choice was forced by
 * an append-only trigger treating `SET NULL` as a mutation; `documents` carries no such trigger,
 * and `BLUEPRINT.md` §25 already describes account deletion as removing a subject's documents
 * alongside crypto-shredding its DEK, so cascading here matches the intended retention behaviour
 * rather than blocking it.
 *
 * `original_filename_ciphertext`/`mime_type`/`size_bytes`/`sha256` are NOT NULL: unlike
 * `tax_subjects`/`users` (`Casts\Encrypted` on a model's *own* id, which cannot exist before the
 * row does — see that migration's docblock), a `Document`'s `encryptionSubjectId()` is its
 * *already-existing* `tax_subject_id` foreign key, which is always set before any encrypted
 * attribute is assigned (`Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst`). No two-phase
 * insert is needed here.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            Tenancy::addColumn($table);

            $table->string('type');
            $table->string('status')->default('QUARANTINE');
            $table->string('rejection_reason')->nullable();

            $table->text('original_filename_ciphertext');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);

            $table->string('object_path');
            $table->unsignedInteger('object_encryption_version');

            $table->string('source')->default('USER_UPLOAD');

            $table->timestampTz('received_at');
            $table->timestampTz('deleted_at')->nullable();

            $table->timestampsTz();

            $table->index(Tenancy::column());
            $table->index([Tenancy::column(), 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
