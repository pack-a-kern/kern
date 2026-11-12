<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The issues an order lists that another order already wrote, by ISIN or code: left as that order wrote
 * them, and said beside the order on the laws screen. An order read before this was kept says nothing —
 * null, not an empty list, since nobody looked.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_treasury_orders', function (Blueprint $table): void {
            $table->json('skipped_issues')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_treasury_orders', function (Blueprint $table): void {
            $table->dropColumn('skipped_issues');
        });
    }
};
