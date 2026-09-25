<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application identity (`BLUEPRINT.md` §5.1) — not a `TaxSubject`. A `User` authenticates and, via
 * `subject_memberships`, may access zero or more `TaxSubject`s (owner of one PF, accountant on
 * several, etc.).
 *
 * `email_ciphertext`/`email_blind_index` follow §4.4's blind-index pattern exactly (HMAC under
 * the single global, versioned key — never a bare hash, never per-record). Login-by-email is
 * `WHERE email_blind_index = HMAC(...)`, which never needs to decrypt a row to find it.
 *
 * `encrypted_dek`/`dek_kek_version`: every `User` gets its own per-record Data Encryption Key,
 * wrapped via the same OpenBao-backed KMS as a `TaxSubject`'s DEK (`Kern\Platform\KeyManagement`,
 * docs/handoff/005-crypto-core.md). BLUEPRINT.md's envelope-encryption design (§4.2) is written in
 * terms of "per-TaxSubject DEK", and a `User` is not a `TaxSubject` — see
 * `docs/handoff/011-identity-tenancy.md` "Key decisions" for why a `User` is treated as its own
 * encryption principal rather than borrowing a `TaxSubject`'s key (in short: a user's own PII —
 * their email — cannot be bound to any single tenant they access, since one user may hold
 * memberships across several `TaxSubject`s with independent blast radii; giving every `User` its
 * own DEK keeps that same blast-radius bound instead of picking one arbitrary tenant to own it,
 * or introducing a third, undocumented key class).
 *
 * `password_hash` is nullable per §5.1 ("passkey-only allowed") — this task builds no
 * authentication flow (passkeys/TOTP/login are a separate, later task), only the column.
 *
 * `email_ciphertext`/`email_blind_index` are nullable at the database level despite being
 * conceptually mandatory — the exact same reason `tax_subjects.display_name_ciphertext` is
 * nullable (see that migration's docblock): `encryptionSubjectId()` for a `User` is its own id, so
 * encrypting `email_ciphertext` requires this row to already exist, which a single-statement
 * `create()` cannot satisfy. `Kern\Platform\Identity\Models\User::createWithEmail()` is the only
 * sanctioned way to create a `User`, and enforces "always set" at the application level in the
 * same two-write shape `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject` uses for `TaxSubject`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->text('email_ciphertext')->nullable();
            $table->char('email_blind_index', 64)->nullable()->unique();

            $table->string('password_hash')->nullable();

            // Wrapped per-user DEK (Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey).
            // Nullable: populated on first encrypted-field write via
            // Kern\Platform\KeyManagement\SubjectDekService — never plaintext, never eagerly
            // generated before it's needed. See Kern\TaxRomania\Subjects\Infrastructure\
            // EloquentSubjectDekRepository for exactly how this column is read/written.
            $table->text('encrypted_dek')->nullable();
            $table->unsignedInteger('dek_kek_version')->nullable();

            $table->enum('status', ['ACTIVE', 'SUSPENDED', 'DELETED'])->default('ACTIVE');
            $table->timestampTz('email_verified_at')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
