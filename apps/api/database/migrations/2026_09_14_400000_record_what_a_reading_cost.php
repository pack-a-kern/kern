<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a reading cost: how long it took and how many tokens it spent.
 *
 * Both come back in the provider's own reply and were discarded. They are the two numbers an
 * operator needs to answer "is this model worth it" — a reading that takes four minutes and sixty
 * thousand tokens is a different proposition from one that takes eight seconds, and until now the
 * only way to tell them apart was to watch the queue.
 *
 * On the extraction rather than the document, because they describe one attempt that produced one
 * reading — and a document can be read more than once.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table): void {
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_extractions', function (Blueprint $table): void {
            $table->dropColumn(['duration_ms', 'prompt_tokens', 'completion_tokens']);
        });
    }
};
