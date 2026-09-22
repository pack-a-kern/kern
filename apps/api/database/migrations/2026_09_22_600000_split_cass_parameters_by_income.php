<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The CASS values named by the income they apply to, as the D212 and the law separate them.
 *
 * - `RO.CASS.BASE_WAGES` (6, 12 and 24 minimum wages) is the base of the income of art. 155 alin. (1)
 *   lit. c)–h) — rent, dividends, interest, investments: `RO.CASS.OTHER_INCOME.BANDS_WAGES`.
 * - `RO.CASS.BASE_CAP_WAGES` was two rules under one name: until 2022 the cap of every income together,
 *   12 minimum wages (`RO.CASS.ALL_INCOME.CAP_WAGES`); from 2024 the cap of independent activities'
 *   income, lit. b), 60 (`RO.CASS.INDEPENDENT.CAP_WAGES`). Each value goes to its rule by the year it
 *   starts — 2023, when the bands began — keeping its status, its source and who approved it.
 */
return new class () extends Migration {
    private const string SPLIT_ON = '2023-01-01';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('fiscal_parameters')->where('code', 'RO.CASS.BASE_WAGES')->update(['code' => 'RO.CASS.OTHER_INCOME.BANDS_WAGES']);

            $cap = DB::table('fiscal_parameters')->where('code', 'RO.CASS.BASE_CAP_WAGES')->value('id');

            if (! is_string($cap)) {
                return;
            }

            $allIncome = $this->parameter('RO.CASS.ALL_INCOME.CAP_WAGES');
            $independent = $this->parameter('RO.CASS.INDEPENDENT.CAP_WAGES');

            DB::table('fiscal_parameter_versions')->where('fiscal_parameter_id', $cap)->where('valid_from', '<', self::SPLIT_ON)->update(['fiscal_parameter_id' => $allIncome]);
            DB::table('fiscal_parameter_versions')->where('fiscal_parameter_id', $cap)->where('valid_from', '>=', self::SPLIT_ON)->update(['fiscal_parameter_id' => $independent]);
            DB::table('fiscal_parameters')->where('id', $cap)->delete();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('fiscal_parameters')->where('code', 'RO.CASS.OTHER_INCOME.BANDS_WAGES')->update(['code' => 'RO.CASS.BASE_WAGES']);

            $ids = DB::table('fiscal_parameters')->whereIn('code', ['RO.CASS.ALL_INCOME.CAP_WAGES', 'RO.CASS.INDEPENDENT.CAP_WAGES'])->pluck('id')->all();

            if ($ids === []) {
                return;
            }

            $cap = $this->parameter('RO.CASS.BASE_CAP_WAGES');
            DB::table('fiscal_parameter_versions')->whereIn('fiscal_parameter_id', $ids)->update(['fiscal_parameter_id' => $cap]);
            DB::table('fiscal_parameters')->whereIn('id', $ids)->delete();
        });
    }

    /** The parameter with this code, made if it is not there yet. */
    private function parameter(string $code): string
    {
        $id = DB::table('fiscal_parameters')->where('code', $code)->value('id');

        if (is_string($id)) {
            return $id;
        }

        $id = (string) Str::uuid7();
        DB::table('fiscal_parameters')->insert(['id' => $id, 'code' => $code, 'value_type' => 'INTEGER', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return $id;
    }
};
