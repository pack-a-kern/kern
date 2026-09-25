<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which source is preferred when two of them publish the same thing.
 *
 * The column belongs here because the repository that upserts an official source from configuration
 * writes it — `OfficialSourceRepository::resolve()` — so a bare installation of this package cannot
 * fetch anything at all without it. It arrived by a side door instead: the application's migration
 * that folded `fx_sources` into the official source added it while moving the exchange-rate module's
 * own priority onto it. That migration now only fills it.
 *
 * Guarded, because in the installation where that happened the column is already there.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('ext_official_sources', 'priority')) {
            return;
        }

        Schema::table('ext_official_sources', function (Blueprint $table): void {
            $table->unsignedSmallInteger('priority')->default(1);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ext_official_sources', 'priority')) {
            return;
        }

        Schema::table('ext_official_sources', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};
