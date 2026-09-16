<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation: what became of every line of an imported statement.
 *
 * A statement whose 12 522 lines became 1 700 transactions is either exactly right or badly wrong,
 * and nobody can tell which from the transaction count alone. Folded lines are detail lines
 * gathered into the transaction above them; skipped lines are the headings and footers the form
 * named. The four numbers add up, so a line that quietly vanished shows as arithmetic that does not
 * balance rather than as a missing payment nobody looks for.
 *
 * `ai_failure_reason` says why no form was suggested when none was — no consent, a refused call, or
 * a model that spent its whole budget thinking. A closed code, never a provider's own words.
 *
 * ## Why this is its own migration
 *
 * These columns belong to `create_statement_imports` by subject, and were briefly added there. That
 * was wrong: the create migration had already run, so every existing installation kept a table
 * without them while the code read them — which is a 500 on the imports list, not a missing
 * feature. A migration that has run anywhere is history; a change to it is a new file.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('statement_imports', function (Blueprint $table): void {
            // Zero for imports made before the counts existed. The screen hides the line rather
            // than claiming a statement with no lines in it.
            $table->unsignedInteger('lines_read')->default(0);
            $table->unsignedInteger('lines_folded')->default(0);
            $table->unsignedInteger('lines_skipped')->default(0);
            $table->string('ai_failure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('statement_imports', function (Blueprint $table): void {
            $table->dropColumn(['lines_read', 'lines_folded', 'lines_skipped', 'ai_failure_reason']);
        });
    }
};
