<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's watches, counted when they keep one more (`legislation.watches.max_per_person`): by an index,
 * which a foreign key alone does not give on Postgres.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_law_watches', function (Blueprint $table): void {
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('ext_law_watches', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
        });
    }
};
