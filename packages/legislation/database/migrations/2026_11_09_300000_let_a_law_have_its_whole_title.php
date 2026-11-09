<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A law's title as the Official Gazette prints it, however long: a Ministry of Finance order approving
 * an issue's final terms is titled in some 400 characters, and `string` stopped it at 255 — the order
 * could not be read at all (found 2026-11-09 reading Fidelis and Tezaur orders, ADR 0025).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->text('title')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->string('title')->nullable()->change();
        });
    }
};
