<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tax_rules` (`BLUEPRINT.md` §6.8) — skeleton only (tables and models, per this task's brief); no
 * `TaxRule`/`TaxRuleContract` implementation exists yet (Milestone 5), and nothing populates this
 * table (Milestone 4/5).
 *
 * `subject_type` is a plain nullable string, deliberately **not** a foreign key or shared enum
 * with `App\Domain\Subjects`' `tax_subjects.type` — importing that module's enum here would be a
 * cross-module code dependency `deptrac.php` does not currently grant `TaxKnowledge`
 * (`docs/AGENTS.md` §6: a module never imports another module's types unless the ruleset says so),
 * and this skeleton has no code that reads or validates the value yet to justify asking for that
 * access. Whoever builds Milestone 5's `TaxRule` implementations decides then whether a shared
 * value object is warranted.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code')->unique();
            $table->string('jurisdiction');
            $table->string('tax_code');
            $table->string('subject_type')->nullable();
            $table->string('income_category')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
    }
};
