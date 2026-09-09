<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tax_facts` (`BLUEPRINT.md` §6.7) — a normalised financial event, the input a `TaxRuleContract`
 * implementation is evaluated against. Never contains a computed tax amount itself; that is what
 * `tax_assessment_lines` (a downstream table) records.
 *
 * **Six columns beyond `BLUEPRINT.md`'s literal `recognition_date`/`cash_date` pair, added
 * deliberately** — `BLUEPRINT.md` §10.3 names them explicitly: "Tax fact păstrează mai multe date
 * dacă relevante: earned_at; received_at; paid_at; distributed_at; contract_date;
 * transaction_date. Rule-ul decide ce dată contează." A given fact typically populates only the
 * one or two dates its own category actually has (a rental accrual has `contract_date`, a
 * dividend has `distributed_at`); all are nullable for that reason. `recognition_date` is the one
 * NOT NULL per the literal schema — every fact must have at least an accounting recognition date
 * even when its category-specific date is unknown/not-yet-relevant. Matches the precedent set by
 * `docs/handoff/018-legislation-ingestion.md` (`source_sections.effective_from/until` added
 * beyond the literal list, documented the same way).
 *
 * **`fx_conversion_json` replaces the literal schema's single `fx_rate_id` FK.** A single foreign
 * key cannot represent every `App\Domain\TaxEngine\Enums\FxConversionMethod` this module supports
 * — `MONTHLY_AVERAGE`/`ANNUAL_AVERAGE` are derived from several `fx_rates` rows, not one. Storing
 * a full `App\Domain\TaxEngine\DataTransferObjects\FxConversionResult` snapshot (method, rate
 * applied, and every contributing rate's provenance: source document version id, content hash,
 * raw object path, retrieval time) satisfies `BLUEPRINT.md` §7.5's reproducibility requirement for
 * every method uniformly, where a single FK only would for `SPOT`/`PREVIOUS_BANKING_DAY`. Null
 * when `currency_code = 'RON'` (no conversion occurred). See that DTO's docblock for the exact
 * shape persisted.
 *
 * `source_type`/`source_id` are a plain polymorphic reference (no FK) — a `TaxFact` can originate
 * from any number of other modules' tables (`Wallet`'s `financial_transactions`, a future
 * `Rental`'s `rental_accruals`, a document extraction, …), most of which do not exist yet and none
 * of which this task may depend on (`docs/AGENTS.md` §6 — no cross-module Eloquent import for
 * modules this task does not own).
 *
 * **`deductible_amount_ron_minor`/`withheld_tax_ron_minor` are beyond the literal schema, added
 * deliberately.** `BLUEPRINT.md`'s literal list gives this table exactly one RON column
 * (`ron_amount_minor`, read here as the gross amount's RON equivalent). A
 * `App\Domain\TaxEngine\Contracts\TaxRuleContract` implementation must never convert currency
 * itself (`App\Domain\TaxEngine\Services\FxConversionPolicy` is orchestration, not rule code), so
 * every amount a rule reads from a fact — gross, deductible, withheld — must already be in RON by
 * the time `App\Domain\TaxEngine\DataTransferObjects\TaxFactSnapshot` is built. Deriving these two
 * from `gross_amount_minor`/`ron_amount_minor`'s implied ratio would silently assume deductible/
 * withheld amounts convert at the *same* rate as gross, which is not always true (a withholding
 * tax can genuinely be assessed/converted separately) — an unverifiable, potentially wrong
 * assumption `docs/AGENTS.md` §2 would forbid making silently. Explicit, independently-populated
 * columns avoid that assumption entirely. Both nullable, following `deductible_amount_minor`/
 * `withheld_tax_minor`'s own nullability.
 *
 * `rule_input_version` (literal schema column) is nullable and unpopulated by this milestone —
 * `BLUEPRINT.md` does not specify what versions this identifies beyond the name, and no caller in
 * this milestone has an opinion; a future task that gives it real semantics can populate it
 * without a schema change. Same treatment M2/M3 gave other under-specified literal columns
 * (`fx_rate_anomalies.resolved_at`, `source_sections.page_start/end`).
 *
 * `metadata_ciphertext` uses `App\Support\Crypto\Casts\Encrypted` (`BLUEPRINT.md` §4.3 — encrypt
 * anything that could carry PII) — see `App\Domain\TaxEngine\Models\TaxFact`'s docblock for the
 * AAD-ordering requirements this implies.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tax_facts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects');
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('category_code');
            $table->string('subtype_code')->nullable();

            $table->date('recognition_date');
            $table->date('cash_date')->nullable();
            $table->date('earned_at')->nullable();
            $table->date('received_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('distributed_at')->nullable();
            $table->date('contract_date')->nullable();
            $table->date('transaction_date')->nullable();

            $table->bigInteger('gross_amount_minor');
            $table->bigInteger('deductible_amount_minor')->nullable();
            $table->bigInteger('withheld_tax_minor')->nullable();
            $table->char('currency_code', 3);
            $table->bigInteger('ron_amount_minor');
            $table->bigInteger('deductible_amount_ron_minor')->nullable();
            $table->bigInteger('withheld_tax_ron_minor')->nullable();
            $table->json('fx_conversion_json')->nullable();

            $table->string('source_type');
            $table->uuid('source_id');

            $table->string('status')->default('PROPOSED');
            $table->text('metadata_ciphertext')->nullable();
            $table->string('rule_input_version')->nullable();

            $table->timestampsTz();

            $table->index(['tax_subject_id', 'fiscal_year', 'category_code'], 'tax_facts_subject_year_category');
            $table->index(['source_type', 'source_id'], 'tax_facts_source_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_facts');
    }
};
