<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `user_recovery_codes` (`BLUEPRINT.md` §6.1) — single-use MFA fallback codes.
 *
 * `code_hash` is a one-way hash (`Illuminate\Support\Facades\Hash`, Argon2id — the same driver
 * `password_hash` uses, `BLUEPRINT.md` §4.7), **not** `App\Support\Crypto\Casts\Encrypted`: a
 * recovery code only ever needs to be *verified*, never redisplayed, and a one-way hash is a
 * strictly stronger guarantee than reversible encryption for a value that must never be recovered
 * even by the application itself — encrypting it as well would add nothing (`docs/CODE-QUALITY.md`
 * §4, no pointless abstraction/mechanism layering).
 *
 * `used_at` is the single-use enforcement: `App\Domain\Identity\Actions\RedeemRecoveryCode`
 * matches the submitted code against every `used_at IS NULL` row for the user (there are only
 * ever a handful, per `config('identity.recovery_codes.count')`), and a replay of an already-used
 * code always fails because its row is excluded from that query — see that class's own tests for
 * the single-use regression proof.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('code_hash');
            $table->timestampTz('used_at')->nullable();

            $table->timestampTz('created_at');

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recovery_codes');
    }
};
