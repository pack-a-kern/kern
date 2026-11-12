<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One kind's notices, read through in pages of their id (`WithdrawNotices`): by an index, not the whole
 * table of every person's notices.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['type', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex(['type', 'id']);
        });
    }
};
