<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wrapped DEK for `App\Domain\Settings\Support\SystemEncryptionScope::ID` — the encryption
 * principal for system-wide secrets that have no `TaxSubject` (e.g. the AI provider API key,
 * `docs/handoff/041-settings-module.md`). Deliberately a **separate** table from
 * `tax_subjects`/`users`, not a new row squeezed into either: both of those tables key
 * `encrypted_dek`/`dek_kek_version` off a native Postgres `uuid` primary key
 * (`App\Domain\Subjects\Infrastructure\EloquentSubjectDekRepository`), and `SystemEncryptionScope::ID`
 * is deliberately *not* a UUID (see that class's docblock — "do not invent a fake subject id").
 * `scope` is a short string primary key so a future second system-wide encryption context (should
 * one ever be needed) does not require a schema change — today `App\Domain\Settings\Infrastructure\
 * SystemDekRepository` only ever reads/writes the single `'system'` row.
 *
 * Same nullable-until-first-use shape as `tax_subjects.encrypted_dek`/`users.encrypted_dek`: no
 * row exists here until the first secret setting is ever written
 * (`Kern\Platform\KeyManagement\SubjectDekService::plaintextDekFor()`'s "generate on first use"
 * path, reused verbatim with a different `SubjectDekRepository` implementation).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('system_encryption_keys', function (Blueprint $table): void {
            $table->string('scope', 64)->primary();

            $table->text('encrypted_dek');
            $table->unsignedInteger('dek_kek_version');

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_encryption_keys');
    }
};
