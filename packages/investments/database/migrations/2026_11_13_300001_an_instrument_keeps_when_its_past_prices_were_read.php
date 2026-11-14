<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a listed instrument's past prices were read from the chart on its BVB page — once, the first time
 * the day's import finds it; null until then.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_financial_instruments', function (Blueprint $table): void {
            $table->timestampTz('history_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_financial_instruments', function (Blueprint $table): void {
            $table->dropColumn('history_read_at');
        });
    }
};
