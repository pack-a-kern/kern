<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ext_banks` — every bank the ECB lists for the configured countries, and the bank a deposit is at.
 *
 * Public reference data: no `tax_subject_id`, nothing encrypted. Filled only by
 * `App\Domain\Banks\Actions\ImportBanks`, never typed — which is the point of it: a deposit's bank
 * is chosen from here instead of being a name somebody spelled.
 *
 * **Keyed by the ECB's own code** (`RIAD_CODE`), the one identifier every row carries. The LEI is
 * missing for most branches and cooperatives, and the BIC is missing where GLEIF has no mapping.
 *
 * **Never deleted.** A bank that leaves the ECB list — merged, closed — becomes `active = false`, so
 * a deposit opened at it years ago still names it. That is also why `deposit_terms.bank_id` restricts
 * deletion rather than cascading.
 *
 * **The 4-letter bank code is not a column.** It is the first four characters of `bic`, and storing
 * both would be two copies of one fact.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_banks', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('ecb_code', 32)->unique();
            $table->string('lei', 20)->nullable()->index();
            $table->string('bic', 11)->nullable();

            // Built from the legal name — see `App\Domain\Banks\Services\BankNameBuilder`.
            $table->string('name');
            $table->text('legal_name');
            $table->text('parent_name')->nullable();
            $table->string('parent_lei', 20)->nullable();

            $table->string('category');
            $table->char('country_code', 2);
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->text('address')->nullable();

            $table->boolean('active')->default(true);
            $table->date('first_seen_on');
            // The date of the ECB file that last listed it, not the day the import ran.
            $table->date('last_seen_on');
            // The date of the GLEIF file the BIC was matched against.
            $table->date('bic_checked_on')->nullable();

            $table->timestampsTz();

            $table->index(['country_code', 'active']);
        });

        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->foreignUuid('bank_id')->nullable()->after('funding_account_id')
                ->constrained('ext_banks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_id');
        });

        Schema::dropIfExists('ext_banks');
    }
};
