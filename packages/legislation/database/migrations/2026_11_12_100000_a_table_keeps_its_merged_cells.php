<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a provision's tables merge cells: for each table, as `tables` lists them, the cells that span
 * more than one column or row — "Cod emisiune 0105" across three columns, "Plata valorii nominale"
 * two wide and two tall. Without it a merged cell stands in one column and every cell after it in
 * the wrong one. Beside `tables` rather than in it: the rows of words stay what they were for all
 * that reads them as words.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_source_sections', function (Blueprint $table): void {
            $table->jsonb('table_spans')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_source_sections', function (Blueprint $table): void {
            $table->dropColumn('table_spans');
        });
    }
};
