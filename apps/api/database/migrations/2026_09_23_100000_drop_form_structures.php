<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The D212 structure files go: the fiscal values are read from the law itself now.
 *
 * ANAF's structure files stated a year's values as the formulas of a declaration, and each formula
 * was read with a pattern written for the wording it happened to use — which left 2019, 2020 and
 * every year no form is published for without values at all, and said nothing when the law changed
 * something a formula does not mention. The law says all of it, for every year, and is read from
 * (`config/ai/legislation.php`); a declaration's own structure is a question for the day this
 * application fills one in, and that is a different file with a different shape.
 *
 * What was approved from a form keeps its evidence: a version's `evidence.readings` holds the
 * file's address and the formula quoted, which is the provenance — the table held nothing else a
 * decision rests on.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('ext_form_structures');
    }

    public function down(): void
    {
        Schema::create('ext_form_structures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('form_code');
            $table->unsignedSmallInteger('form_year');
            $table->text('url')->unique();
            $table->char('content_hash', 64);
            $table->jsonb('unmatched')->nullable();
            $table->timestampTz('read_at');
            $table->timestampsTz();

            $table->index(['form_code', 'form_year']);
        });
    }
};
