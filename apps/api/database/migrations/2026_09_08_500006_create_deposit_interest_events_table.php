<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deposit_interest_events` (`BLUEPRINT.md` §6.4). `tax_subject_id`/`currency_code` are added beyond
 * the blueprint's literal column list; `currency_code` denormalises `deposits.currency_code` at
 * the moment the event is generated (`docs/handoff/021-wallet.md` explains why: a point-in-time
 * event record should not change meaning if a future feature ever allowed editing a deposit).
 *
 * `withholding_minor` nullable / `net_interest_minor` not nullable, exactly as `BLUEPRINT.md` §6.4
 * lists them: `App\Domain\Wallet\Services\Interest\DepositInterestCalculator` computes only
 * `gross_interest_minor` (pure day-count/rate math, no fiscal knowledge —
 * `docs/AGENTS.md` §6: no fiscal logic outside `TaxEngine`). Withholding is deliberately never
 * computed by this module — no Romanian withholding-tax rate is hard-coded here, since that rate
 * is fiscal domain knowledge requiring a `source_section_id` and belongs exclusively to
 * `Domain/TaxEngine` (not built at the time this module was written). Until something calls
 * `App\Domain\Wallet\Actions\RecordDepositInterestWithholding`, `net_interest_minor` equals
 * `gross_interest_minor` (withholding defaults to nothing withheld yet), which is the honest,
 * literal state, not a guess.
 *
 * **FX provenance columns** (`fx_*`, `ron_equivalent_minor`) implement this milestone's stated
 * definition of done (`BLUEPRINT.md` §7.5): every conversion this module performs keeps the amount,
 * currency, rate, date, source, method, and result together on the row that used it. Populated
 * only when `deposits.currency_code <> 'RON'` — see
 * `App\Domain\Wallet\Services\Fx\DepositInterestFxAnnotator`. `fx_source_document_version_id` is
 * a real foreign key into `Domain/TaxKnowledge`'s `source_document_versions` table — a raw
 * database referential-integrity constraint, not an Eloquent model import across the module
 * boundary `docs/AGENTS.md` §6 governs (the same distinction
 * `2026_09_08_200005_add_identity_foreign_keys_to_audit_tables.php` already establishes in this
 * codebase: a migration may reference another module's table at the schema level even though
 * application code may never import its Eloquent model).
 *
 * No `updated_at`/`created_at`: this table's literal column list in `BLUEPRINT.md` has none, and
 * (like `transaction_splits`) row age/insertion order is already recoverable from the UUIDv7
 * primary key.
 *
 * Uniqueness on `(deposit_id, accrual_from, accrual_to)` is what makes
 * `App\Domain\Wallet\Actions\GenerateDepositInterestSchedule` idempotent: regenerating a deposit's
 * schedule resolves to the same rows by construction rather than needing a separate
 * "does this period already exist" check.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('deposit_interest_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('deposit_id');
            $table->foreign('deposit_id')->references('id')->on('deposits')->cascadeOnDelete();

            $table->date('accrual_from');
            $table->date('accrual_to');

            $table->char('currency_code', 3);
            $table->bigInteger('gross_interest_minor');
            $table->bigInteger('withholding_minor')->nullable();
            $table->bigInteger('net_interest_minor');

            $table->date('paid_at')->nullable();

            $table->uuid('transaction_id')->nullable();
            $table->foreign('transaction_id')->references('id')->on('financial_transactions')->nullOnDelete();

            $table->string('calculation_version');

            $table->string('fx_rate_decimal', 32)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_source_code')->nullable();
            $table->string('fx_method')->nullable();
            $table->char('fx_content_sha256', 64)->nullable();
            $table->uuid('fx_source_document_version_id')->nullable();
            $table->foreign('fx_source_document_version_id')->references('id')->on('source_document_versions')->nullOnDelete();
            $table->bigInteger('ron_equivalent_minor')->nullable();

            $table->index('tax_subject_id');
            $table->index(['deposit_id', 'accrual_from']);
            $table->unique(['deposit_id', 'accrual_from', 'accrual_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_interest_events');
    }
};
