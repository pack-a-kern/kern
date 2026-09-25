<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a stretch of a provision's history starts on the day the portal's own note gives, not on
 * the consolidation it was first read in.
 *
 * The portal lists no consolidation of the Codul fiscal between December 2016 and 2021, so a wording
 * changed in 2018 is first read in 2021's — but it says, in its note, "(la 01-01-2018, … a fost
 * modificat de … ORDONANȚA DE URGENȚĂ nr. 79/2017)". A stretch so dated is marked, for a reader to
 * see where its date comes from.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_section_histories', function (Blueprint $table): void {
            $table->boolean('dated_by_note')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ext_section_histories', function (Blueprint $table): void {
            $table->dropColumn('dated_by_note');
        });
    }
};
