<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives every existing taxpayer the general source new ones now get at registration.
 *
 * Written with the query builder rather than through `EnsureGeneralSource`, because
 * `display_name_ciphertext` is encrypted under the subject's own DEK and a migration has no
 * authenticated key context to seal one with. It does not need one: the screen renders a translated
 * label for this kind, so the column stays null — the row exists to be a destination, not to be
 * read.
 */
return new class () extends Migration {
    public function up(): void
    {
        $subjects = DB::table('tax_subjects')
            ->whereNotIn('id', DB::table('financial_accounts')->where('kind', 'GENERAL')->select('tax_subject_id'))
            ->pluck('id');

        foreach ($subjects as $subjectId) {
            DB::table('financial_accounts')->insert([
                'id' => (string) Str::uuid7(),
                'tax_subject_id' => $subjectId,
                'kind' => 'GENERAL',
                'institution_name' => null,
                // No name at all: a migration has no subject key to seal one with, and the screen
                // renders a translated label for `GENERAL` rather than reading this column.
                'display_name_ciphertext' => null,
                'currency_code' => null,
                'source' => 'MANUAL',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Left in place: a source with transactions against it cannot be removed without deciding
        // where they go, and that is not a decision a rollback should make silently.
    }
};
