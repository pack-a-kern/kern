<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times a search asked the portal again before it answered: the summary a finished search shows,
 * beside what it found. A search already run counts none, which is all that is known of it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_portal_searches', function (Blueprint $table): void {
            $table->unsignedInteger('retries')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ext_portal_searches', function (Blueprint $table): void {
            $table->dropColumn('retries');
        });
    }
};
