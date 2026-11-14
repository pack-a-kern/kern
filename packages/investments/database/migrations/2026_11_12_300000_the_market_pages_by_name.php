<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The market reads one page at a time: one status's instruments by name, the id breaking a tie — in this
 * order, so a page is read off the index rather than sorted whole.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_financial_instruments', function (Blueprint $table): void {
            $table->index(['status', 'name', 'id'], 'financial_instruments_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('ext_financial_instruments', function (Blueprint $table): void {
            $table->dropIndex('financial_instruments_by_name');
        });
    }
};
