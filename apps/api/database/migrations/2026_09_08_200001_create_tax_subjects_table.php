<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tax_subjects` (`BLUEPRINT.md` §5.2/§2.2) — the tenant boundary every business table scopes to.
 * `type` includes PFA/II/IF/SRL in the schema per §2.2 ("extensibility through good abstractions,
 * not dead code for future functions") but **no application logic exists for anything but PF and
 * PFA** — see `App\Domain\Subjects\Enums\SubjectType`, `docs/handoff/011-identity-tenancy.md`, and
 * `docs/handoff/034-pfa-extension.md`.
 *
 * `fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index`/`fiscal_identifier_kind` are the
 * **single canonical location for any subject's fiscal identifier, whatever its type** — a PF's
 * CNP, a PFA's business identifier, and whatever identifier a future II/IF/SRL profile needs, all
 * live here and nowhere else (ADR 0011, `docs/adr/0011-canonical-fiscal-identifier.md`, resolving
 * a real contradiction between `BLUEPRINT.md` §5.2 and §6.2). `fiscal_identifier_kind` is a plain,
 * non-sensitive enum column (`App\Domain\Subjects\Enums\FiscalIdentifierKind`) — knowing that a
 * subject's identifier *is a CNP* is not itself PII, and this column is what lets a reader tell
 * which kind of identifier a matched row actually holds, since the blind index alone does not
 * (see that enum's own docblock). `fiscal_identifier_blind_index` stays a single-column `UNIQUE`
 * index, not a composite `UNIQUE (kind, blind_index)`: the kind is already bound into the hashed
 * input (`FiscalIdentifierKind::keyedValue()`), so two different (kind, raw value) pairs already
 * cannot produce equal blind-index values — a composite key would duplicate that guarantee while
 * adding a way for a future bug to write a `kind` that disagrees with what the hash actually
 * encodes and still pass the constraint. See the ADR for the full reasoning.
 *
 * `encrypted_dek`/`dek_kek_version` are nullable and populated on first encrypted-field write
 * (`App\Support\KeyManagement\SubjectDekService`, docs/handoff/005-crypto-core.md) — deliberately
 * NOT eagerly generated at row-insert time. This resolves handoff 005's open question about the
 * create-only race: a `TaxSubject` is inserted first with these two columns null (and, therefore,
 * with `display_name_ciphertext`/`fiscal_identifier_ciphertext` also still null — a row must exist
 * before `Casts\Encrypted` can bind an encrypted field's AAD to this row's own id, since a
 * `TaxSubject`'s `encryptionSubjectId()` is its own primary key), then updated in a second write
 * that assigns the encrypted fields — which is exactly the write that triggers first-use DEK
 * creation. See `App\Domain\Subjects\Actions\RegisterPfSubject`/`RegisterPfaSubject` for the
 * two-step sequence, wrapped in one DB transaction.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tax_subjects', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->enum('type', ['PF', 'PFA', 'II', 'IF', 'SRL']);
            $table->char('country_code', 2)->default('RO');

            $table->text('display_name_ciphertext')->nullable();
            $table->text('fiscal_identifier_ciphertext')->nullable();
            $table->char('fiscal_identifier_blind_index', 64)->nullable()->unique();
            $table->enum('fiscal_identifier_kind', ['CNP', 'CUI'])->nullable();

            $table->text('encrypted_dek')->nullable();
            $table->unsignedInteger('dek_kek_version')->nullable();

            $table->enum('status', ['ACTIVE', 'SUSPENDED', 'CLOSED'])->default('ACTIVE');

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_subjects');
    }
};
