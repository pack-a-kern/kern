<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The currency this subject's screens add up in.
 *
 * ## Why per subject rather than per user or per browser
 *
 * A total is a fact about one tax subject's money, and one user may hold several subjects with
 * different currencies. Storing it on the subject also means it follows the person to a second
 * device, which a `localStorage` preference would not — and unlike the interface language, this
 * choice changes a *number* on the screen, not just its wording.
 *
 * ## It is a display setting, and only that
 *
 * `BLUEPRINT.md` §7.4 is explicit that a fiscal conversion is a decision each `TaxRule` makes for
 * itself, with the rate, the date and the source recorded beside the result. Nothing here is
 * allowed to stand in for that. This column exists so a dashboard can answer "roughly how much do I
 * have", which is a question no tax rule ever asks — the converted figure is always shown with the
 * rate and the date it used, and is never stored.
 *
 * Defaults to RON: this is a Romanian tax product, and the BNR publishes rates *as* RON per unit,
 * so RON is the one currency the conversion needs no cross-rate for.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('tax_subjects', function (Blueprint $table): void {
            // ISO 4217, stored in clear: it is a preference, not personal data, and the totals
            // query needs to read it without a decrypt.
            $table->char('display_currency', 3)->default('RON');
        });
    }

    public function down(): void
    {
        Schema::table('tax_subjects', function (Blueprint $table): void {
            $table->dropColumn('display_currency');
        });
    }
};
