<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A classified split records *where* the money came from, not only what it was.
 *
 * Declarația Unică declares foreign income in a section of its own, **per country and per type** —
 * foreign rent and foreign dividends are different rows there, taxed differently and often under a
 * treaty. So the country cannot be folded into the category: a single `FOREIGN_INCOME` code would
 * destroy exactly the distinction the form asks for, and `RENT` alone cannot say whether it belongs
 * in the domestic section or the German row of the foreign one.
 *
 * Nullable, and null means Romania. Most splits a person records are domestic, and a required field
 * answered the same way every time is a field that stops being read — the classification dialog
 * defaults it and only asks when a person says the income was foreign.
 *
 * ISO 3166-1 alpha-2, matching `config/subjects.php`'s country handling.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('transaction_splits', function (Blueprint $table): void {
            $table->char('country_code', 2)->nullable()->after('category_code');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_splits', function (Blueprint $table): void {
            $table->dropColumn('country_code');
        });
    }
};
