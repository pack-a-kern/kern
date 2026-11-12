<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The laws screen reads one page at a time: one source's acts, oldest first, the id breaking a tie
 * between acts of one day — in this order, so a page is read off the index rather than sorted whole.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->index(['official_source_id', 'act_date', 'id'], 'source_documents_by_act_date');
        });
    }

    public function down(): void
    {
        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->dropIndex('source_documents_by_act_date');
        });
    }
};
