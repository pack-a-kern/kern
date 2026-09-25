<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `user_mfa_methods` (`BLUEPRINT.md` §6.1) — currently TOTP only (`Kern\Platform\Identity\Enums\
 * MfaMethodType`). The schema's `type` enum is intentionally single-valued for now (`BLUEPRINT.md`
 * §2.2's "extensibility through good abstractions, not dead code for future functions" — the same
 * pattern `tax_subjects.type` uses for PFA/II/IF/SRL): adding a second MFA type later (e.g. a
 * hardware OTP token) is a new enum case plus a new implementation, not a schema change.
 *
 * `secret_ciphertext` is the raw TOTP shared secret (`Kern\Platform\Crypto\Casts\Encrypted`) —
 * unlike a WebAuthn public key, this genuinely is a symmetric secret whose disclosure lets an
 * attacker generate valid codes, so it is encrypted under **the owning `User`'s own DEK**, not a
 * fresh DEK of its own: `Kern\Platform\Identity\Models\UserMfaMethod::encryptionSubjectId()` returns
 * `user_id`, reusing exactly the same DEK `users.email_ciphertext` is encrypted under (see
 * `Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst`, overridden to read `user_id` instead
 * of the trait's default `tax_subject_id`).
 *
 * `confirmed_at` is null until the user proves possession of the secret with one valid code during
 * enrollment (`Kern\Platform\Identity\Actions\ConfirmTotpEnrollment`) — an unconfirmed method must
 * never count toward "this user has MFA enrolled" (the Milestone 10 SPV gate,
 * `Kern\Platform\Identity\Support\SpvMfaPolicy`, checks `confirmed_at !== null`).
 *
 * At most one TOTP method per user: re-enrollment replaces the existing row rather than
 * accumulating unconfirmed ones (`docs/CODE-QUALITY.md` — no benefit to allowing several).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_mfa_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->enum('type', ['TOTP']);
            $table->text('secret_ciphertext');

            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();

            $table->timestampsTz();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mfa_methods');
    }
};
