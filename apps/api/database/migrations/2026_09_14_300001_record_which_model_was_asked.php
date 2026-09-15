<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which model was asked, on the document itself.
 *
 * `document_extractions` has recorded the provider and model since it existed — but only for a
 * reading that succeeded, because a failure creates no extraction row. So the one case where the
 * question matters most, "it failed: on which model?", was the one case nothing could answer, and
 * an administrator changing models had no way to tell an old failure from a new one.
 *
 * Overwritten per attempt, beside `ai_extraction_outcome`, because the two describe the same event:
 * what was asked, and what came of it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('ai_model_provider')->nullable();
            $table->string('ai_model_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['ai_model_provider', 'ai_model_name']);
        });
    }
};
