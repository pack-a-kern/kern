<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `user_sessions` (`BLUEPRINT.md` §4.7 "device/session management", §6.1) — a queryable record of
 * every fully-authenticated login session, independent of the actual session store (Redis,
 * `config('session.driver')`). This table is what lets a user see "where am I logged in" and
 * revoke one device without needing to enumerate Redis keys; the real session payload (CSRF
 * token, Inertia state, ...) still lives entirely in Redis exactly as `config/session.php`
 * describes — this table never duplicates that payload, only metadata about it.
 *
 * `id` **is** the underlying session id (`Illuminate\Session\Store::getId()`), not a surrogate —
 * `App\Domain\Identity\Support\SessionRegistrar::revoke()` needs it verbatim to also destroy the
 * matching row in the real session store (`Illuminate\Contracts\Session\Session::getHandler()->
 * destroy($id)`), so a surrogate id would only add a lookup with no benefit.
 *
 * `ip_address`/`user_agent` are stored **plaintext**, not through `Kern\Platform\Crypto\Casts\
 * Encrypted`. `BLUEPRINT.md` §4.3's mandatory-encryption list is CNP/ID/address/phone/IBAN/account
 * number/SPV credentials/tokens/documents/bank details/counterparties/private notes/OCR
 * output/PII-bearing transaction text — a login IP and browser user-agent string are ordinary
 * session-security metadata of the kind every mainstream product shows a user about their own
 * account ("logged in from Chrome on Windows, 86.x.x.x"), not identity documents or financial
 * data. Encrypting them would only prevent the feature they exist for (a fast, indexable "your
 * active sessions" list) without closing any threat this table is exposed to beyond what
 * `BLUEPRINT.md` §21.4's ordinary access-control tests already cover (a session row is only ever
 * readable by its owning user).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->string('id', 100)->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestampTz('last_used_at');
            $table->timestampTz('created_at');
            $table->timestampTz('revoked_at')->nullable();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
