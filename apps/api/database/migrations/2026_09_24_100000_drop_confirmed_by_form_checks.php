<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `confirmed_by_form` check goes with the form reader that wrote it.
 *
 * It said that ANAF's D212 structure file for a year stated the same minimum wage as the decision —
 * a second opinion from a file this application no longer keeps (`2026_09_23_100000`). Nothing
 * writes it, nothing translates it, and the screen printed the bare key beside an approved value.
 *
 * Only the check goes. What the value rests on — the Government's decision and the paragraph quoted
 * from it, in `evidence.readings` — is untouched, and no value changes.
 */
return new class () extends Migration {
    private const string KIND = 'confirmed_by_form';

    public function up(): void
    {
        DB::transaction(function (): void {
            $rows = DB::table('fiscal_parameter_versions')
                ->whereRaw("evidence->'checks' @> ?", [json_encode([['kind' => self::KIND]])])
                ->get(['id', 'evidence']);

            foreach ($rows as $row) {
                $evidence = json_decode((string) $row->evidence, true);

                if (! is_array($evidence) || ! is_array($evidence['checks'] ?? null)) {
                    continue;
                }

                $evidence['checks'] = array_values(array_filter(
                    $evidence['checks'],
                    static fn (mixed $check): bool => ! is_array($check) || ($check['kind'] ?? null) !== self::KIND,
                ));

                DB::table('fiscal_parameter_versions')->where('id', $row->id)->update(['evidence' => json_encode($evidence)]);
            }
        });
    }

    /** The file the check was read from is gone, so there is nothing to put back. */
    public function down(): void
    {
    }
};
