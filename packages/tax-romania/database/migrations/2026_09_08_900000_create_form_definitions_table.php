<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `form_definitions` (`BLUEPRINT.md` §6.10) — the registry row for one ANAF form family (e.g. D212).
 * A definition never carries fiscal content itself; every versioned detail (order, schema,
 * instructions, applicability, validators, generator — §12.2) lives on `form_versions`. This
 * table is global, not tenant-scoped: a form definition is not owned by any one `TaxSubject`,
 * exactly like `Kern\Legislation`'s `tax_rules`/`fiscal_parameters` registries.
 *
 * `code` is UNIQUE — this task never seeds a real "D212" row (`docs/AGENTS.md` §2: no fabricated
 * ANAF content), but the constraint holds for whichever task does.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('form_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('issuer');

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_definitions');
    }
};
