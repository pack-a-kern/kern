<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ext_treasury_orders` — a Ministry of Finance order this package has read for its issues, and how many
 * it held (ADR 0025 §5). An order with none — one amending a prospectus — is recorded all the same, so no
 * order is read twice. The law itself is `kern/legislation`'s: deleting it removes this record with it,
 * and the next run reads it again if it is imported again.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_treasury_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_document_id')->unique()->constrained('ext_source_documents')->cascadeOnDelete();
            $table->string('programme', 16);
            $table->unsignedSmallInteger('issues_count');
            $table->timestampTz('read_at');
            $table->timestampsTz();

            // Whether a programme was ever read: its first run searches every year.
            $table->index('programme');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_treasury_orders');
    }
};
