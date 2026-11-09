<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ext_issuer_profiles` — a company as its BVB page last showed it: its shareholders' meetings and who
 * holds its shares (ADR 0025 §2). One per issuer, not per instrument: a company with shares and bonds
 * has one profile. Read only when asked for, never by a schedule.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_issuer_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('issuer_key', 16)->unique();
            $table->json('meetings');
            $table->json('shareholders');
            // The day the company reported that structure as of, when its page says.
            $table->date('shareholders_as_of')->nullable();
            $table->timestampTz('read_at');
            $table->foreignUuid('evidence_id')->constrained('ext_source_document_versions');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_issuer_profiles');
    }
};
