<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System-wide, admin-editable runtime settings (`docs/handoff/041-settings-module.md`) — e.g. the
 * AI document-extraction provider (`ai.provider`/`ai.base_url`/`ai.model`/`ai.api_key`/
 * `ai.enabled`, `App\Domain\Settings\Enums\SettingKey`). Deliberately **not** scoped to a
 * `tax_subject_id` — these settings apply to the whole deployment, per the task's decision that
 * this is a system-wide, not per-subject, concern.
 *
 * `key` is the primary key (e.g. `"ai.api_key"`) rather than a generated UUID: the set of valid
 * keys is a small, closed enum (`SettingKey`), not a general-purpose arbitrary key/value store,
 * and the key string itself is exactly the stable row identity `Kern\Platform\Crypto\AssociatedData`
 * needs as its AAD's `record_uuid` segment (`App\Domain\Settings\Support\SystemSecretCipher`) —
 * a system-controlled identifier, never free-form user input, matching that class's own "safe to
 * use unescaped" contract.
 *
 * `is_secret` is a plain, non-sensitive bookkeeping column, not the authority on whether a value
 * *should* be encrypted (that is `SettingKey::isSecret()`, a static code-level decision) — it
 * records, per row, which column (`value_plain` vs `value_ciphertext`) that row's write actually
 * used, so a reader never has to trust the current code's enum to interpret a historical row.
 * Exactly one of `value_plain`/`value_ciphertext` is populated at any time; both are nullable
 * because a setting key can exist with no value yet (e.g. immediately after `DELETE
 * .../settings/ai/api-key` clears it without deleting the row, to preserve `updated_at`/
 * `updated_by` provenance).
 *
 * `updated_by` is a plain (unencrypted) reference to `users.id` — knowing *which admin* last
 * changed a setting is not itself sensitive, and every change is additionally recorded in the
 * tamper-evident audit chain (`BLUEPRINT.md` §4.8, `App\Domain\Settings\Services\
 * AiProviderSettingsService`) — this column is a convenience for the settings screen, not the
 * audit trail itself.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 64)->primary();

            $table->boolean('is_secret');
            $table->text('value_plain')->nullable();
            $table->text('value_ciphertext')->nullable();

            $table->uuid('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Precision 6 (microseconds) is mandatory, not cosmetic — the exact same trap
            // `2026_09_08_100001_create_audit_events_table.php` documents for `occurred_at`:
            // `App\Domain\Settings\Repositories\SettingsRepository::latestUpdateMeta()` orders by
            // `updated_at` to answer "which of these keys changed most recently", and
            // `Blueprint::timestampTz()`'s default precision-0 (whole seconds) would make two
            // updates in the same second indistinguishable, silently picking an arbitrary one.
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
