<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a law's reading stopped on this installation's side (`ReadingStop`), and when — cleared when it is
 * read again. A law's own page and the list of laws show it; nothing searches by it, so no index.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->string('reading_stopped', 32)->nullable();
            $table->timestampTz('reading_stopped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->dropColumn(['reading_stopped', 'reading_stopped_at']);
        });
    }
};
