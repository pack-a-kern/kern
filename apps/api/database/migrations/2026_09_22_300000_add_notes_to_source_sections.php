<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The portal's notes on a provision, kept beside it.
 *
 * A note is where the portal copies what an amending act says about when its change applies —
 * "prevederile art. 6 pct. 1 se aplică începând cu anul fiscal 2026" — which the consolidation's own
 * date does not say. Each note is its text, the acts it quotes and the dates it gives, as
 * `App\Domain\TaxKnowledge\Services\NoteReader` read them; never part of the provision's `text`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_source_sections', function (Blueprint $table): void {
            $table->jsonb('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_source_sections', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
