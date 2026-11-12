<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bonds read from one order, found by it: what deleting the order asks, and what counts the holdings
 * that keep it — by an index, which a foreign key alone does not give on Postgres.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_financial_instruments', function (Blueprint $table): void {
            $table->index('terms_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('ext_financial_instruments', function (Blueprint $table): void {
            $table->dropIndex(['terms_document_id']);
        });
    }
};
