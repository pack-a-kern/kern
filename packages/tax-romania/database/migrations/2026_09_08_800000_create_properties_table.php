<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `properties` (`BLUEPRINT.md` §6.5, Milestone 8). Scoped to `tax_subject_id` per
 * `Kern\Platform\Tenancy\ScopedToTenant` (`docs/handoff/011-identity-tenancy.md`).
 *
 * `address_ciphertext`/`cadastral_ref_ciphertext` encrypted per §4.3 — see
 * `Kern\TaxRomania\Rental\Models\Property`'s docblock for why the cadastral reference is included even
 * though §4.3 does not name it literally.
 *
 * `ownership_percent_decimal` is `string`, not `decimal`/`NUMERIC` — the same, by-now-standard
 * reason every other rate/decimal-shaped column in this codebase is a string
 * (`docs/handoff/016-postgres-suite-stabilisation.md`): SQLite's `NUMERIC` affinity silently
 * round-trips through a lossy PHP float. `string(8)` comfortably fits `"100.00"` down to `"0.01"`.
 * §33's co-ownership constraint is what this column exists to satisfy — it is read by
 * `Kern\TaxRomania\Rental\Services\OwnershipShareCalculator` for every accrual this module generates.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->string('type');
            $table->text('address_ciphertext');
            $table->text('cadastral_ref_ciphertext')->nullable();
            $table->string('ownership_percent_decimal', 8);
            $table->date('acquired_at')->nullable();

            $table->timestampsTz();

            $table->index('tax_subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
