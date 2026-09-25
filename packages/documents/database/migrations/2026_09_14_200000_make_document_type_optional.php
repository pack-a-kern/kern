<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document arrives without a type, and is told what it is by what is read out of it.
 *
 * Choosing the type at upload asked the wrong person at the wrong moment: nobody correctly tags
 * forty files before uploading them, the picker defaulted to `RECEIPT`, and an invoice tagged as a
 * receipt was then asked the questions a receipt is asked — a wrong reading caused entirely by a
 * dropdown. The classifier is the thing that has actually read the document.
 *
 * Nullable rather than an `UNKNOWN` enum case: "we have not read it yet" is the absence of an
 * answer, not a kind of document, and an `UNKNOWN` case would need handling in every `match` that
 * switches on a type for the rest of the codebase's life.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('type')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Anything still unclassified becomes OTHER: the column cannot go back to NOT NULL with
        // nulls in it, and OTHER is the case that means "none of the shapes we know".
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('type')->nullable()->change();
        });

        \Illuminate\Support\Facades\DB::table('documents')->whereNull('type')->update(['type' => 'OTHER']);

        Schema::table('documents', function (Blueprint $table): void {
            $table->string('type')->nullable(false)->change();
        });
    }
};
