<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A watch's last run kept as counts the screen words in its own language — found, read in, already laws,
 * ignored, left for later, failed, asked again, and why it stopped — instead of one English sentence; and
 * when a person asked it to run now, until that run ends. A run kept before this said only its sentence,
 * which is dropped: the next run says it again.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_law_watches', function (Blueprint $table): void {
            $table->json('last_summary')->nullable();
            $table->timestampTz('run_requested_at')->nullable();
        });

        Schema::table('ext_law_watches', function (Blueprint $table): void {
            $table->dropColumn('last_result');
        });
    }

    public function down(): void
    {
        Schema::table('ext_law_watches', function (Blueprint $table): void {
            $table->string('last_result', 500)->nullable();
        });

        Schema::table('ext_law_watches', function (Blueprint $table): void {
            $table->dropColumn(['last_summary', 'run_requested_at']);
        });
    }
};
