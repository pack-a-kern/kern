<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fiscal_parameters` (`BLUEPRINT.md` §6.8) — the named, versioned quantities a tax rule reads (e.g.
 * `RO.MIN_WAGE.CASS`). This table and `fiscal_parameter_versions` are a **skeleton**: tables and
 * models only, per this task's brief — nothing populates them yet (that is Milestone 4's job,
 * building the 2020-2026 dataset), and no `TaxRule` implementation reads them yet (Milestone 5).
 *
 * `value_type` is `Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType`
 * (`MONEY|DECIMAL|INTEGER|DATE|JSON`, `BLUEPRINT.md` §6.8 verbatim) — declares how
 * `fiscal_parameter_versions.value_json` must be interpreted, so a consumer never has to guess.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_parameters', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code')->unique();
            $table->string('value_type');
            $table->text('description')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_parameters');
    }
};
