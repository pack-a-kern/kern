<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One outstanding "prove you can read this" challenge, per user, per channel.
 *
 * ## Why a code and not a signed link
 *
 * A signed URL is Laravel's default and it is one click, but it is also email-shaped: a long URL is
 * useless read aloud, awkward in an SMS and worse in a WhatsApp message. Six digits work everywhere.
 * So the code is the primitive and a link, if one is ever added to the mail, is a convenience that
 * fills it in — `Kern\Platform\Identity\Enums\VerificationChannel` is where a second channel arrives,
 * and it needs nothing from this table but a new value in `channel`.
 *
 * ## Why the code is hashed
 *
 * Same reason `user_recovery_codes.code_hash` is: a table read by an attacker must not hand out
 * working codes. It is a one-way `Hash::make()` value, never `Casts\Encrypted` — nothing ever needs
 * to read a code back, only to compare one against it.
 *
 * ## Why the destination is a blind index
 *
 * The address a code was sent to is worth recording — it is what the verification actually proves —
 * and storing it in the clear would be a second copy of an email this schema keeps encrypted. The
 * blind index answers "was this code sent to the address the account now holds" without a second
 * plaintext of it anywhere. It is also what makes a challenge worthless after the address changes.
 *
 * ## Why `attempts` is a column
 *
 * The rate limiter is the first line, and it lives in the cache. A cache can be flushed, a cache can
 * be a different cache after a deploy, and neither of those should hand somebody a fresh million
 * guesses. This column is the ceiling that survives all of it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_verification_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascades: a challenge is meaningless without the account it belongs to, and an account
            // being erased must not leave a record of the address it used behind.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('channel', 32);
            $table->char('destination_blind_index', 64);
            $table->string('code_hash');

            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestampsTz();

            // The one query that runs on every submission: this user's newest live challenge on this
            // channel. Consumed and expired rows are kept — they are the record that a code was sent,
            // and how many times somebody guessed at it.
            $table->index(['user_id', 'channel', 'consumed_at'], 'user_verification_challenges_live');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_verification_challenges');
    }
};
