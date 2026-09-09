<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spv_connections` (`BLUEPRINT.md` §6.11). One row per tax subject's link to ANAF's SPV, for either
 * documented auth route (`docs/reference/anaf-spv-integration.md`): the PF username/password/OTP
 * portal flow, or a qualified digital certificate.
 *
 * Deliberately **no** `password_ciphertext` column — `BLUEPRINT.md` §6.11 says so explicitly, and
 * §13.3/§34 rule out permanent SPV password storage entirely. `credential_policy` records that
 * this is a structural guarantee, not just an omission a future migration could quietly undo
 * (`App\Domain\Spv\Enums\SpvCredentialPolicy` has exactly one case today for the same reason
 * `App\Domain\Identity\Enums\MfaMethodType` has exactly one).
 *
 * `username_ciphertext`/`username_blind_index` only apply to `USER_PASSWORD_OTP` connections — a
 * certificate holder authenticates by certificate, not by a stored username (`docs/reference/
 * anaf-spv-integration.md`: "the certificate holder gets access to every CIF/CNP they are
 * authorised for"). Both stay `null` for a `CERTIFICATE` connection.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('spv_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();

            $table->string('auth_method');
            $table->string('credential_policy');

            $table->text('username_ciphertext')->nullable();
            $table->string('username_blind_index')->nullable();

            $table->string('status');
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_error_code')->nullable();

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->unique(['tax_subject_id', 'username_blind_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spv_connections');
    }
};
