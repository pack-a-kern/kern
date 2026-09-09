<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `user_passkeys` (`BLUEPRINT.md` §6.1) — one row per registered WebAuthn credential
 * (`App\Domain\Identity\Models\UserPasskey`).
 *
 * `public_key` and `credential_id` are stored **plaintext** (base64url), not through
 * `App\Support\Crypto\Casts\Encrypted`, despite this module's general "encrypt everything
 * identity-adjacent" posture. This is a deliberate exception, not an oversight: a WebAuthn
 * credential public key is, by construction, not a secret — the whole point of asymmetric
 * signature verification is that the verifier only ever needs the *public* half, and the
 * `credential_id` is transmitted in cleartext by the browser on every ceremony already. Encrypting
 * either would add `SubjectDekService`/KMS round-trips to every login assertion for no
 * confidentiality benefit (`docs/CODE-QUALITY.md` §4's "no pointless abstraction" reasoning
 * applies here to a pointless *encryption*, not just an abstraction). `credential_id` must also
 * remain exactly matchable at lookup time — it is how `App\Domain\Identity\Support\Webauthn\
 * WebauthnCredentialRepository` finds which stored credential an incoming assertion claims to be.
 *
 * `sign_count` is the WebAuthn spec's clone-detection counter (`BLUEPRINT.md` §21.4-adjacent —
 * `Webauthn\CeremonyStep\CheckCounter` rejects an assertion whose counter did not strictly
 * increase, which is exactly the signal that two devices are sharing one cloned credential).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_passkeys', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->text('credential_id')->unique();
            $table->text('public_key');
            $table->uuid('aaguid');
            $table->string('attestation_type', 32)->default('none');
            $table->jsonb('transports')->default('[]');

            $table->unsignedBigInteger('sign_count')->default(0);
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backup_status')->nullable();
            $table->boolean('uv_initialized')->nullable();

            $table->string('name')->nullable();
            $table->timestampTz('last_used_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_passkeys');
    }
};
