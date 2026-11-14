<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a listed instrument's BVB page shows beside its price, kept with the rest of its profile: the best
 * bid and ask, and a bond's issue — its current coupon, its yield to maturity at the current price, how
 * many were issued and for how much. Each null where the page leaves it out — a share's coupon — and for a
 * profile read before this. Decimals as text, as everywhere in this package (`Decimal`).
 */
return new class () extends Migration {
    private const array DECIMALS = ['bid', 'ask', 'coupon_rate', 'yield_to_maturity', 'issue_value'];

    public function up(): void
    {
        Schema::table('ext_issuer_profiles', function (Blueprint $table): void {
            foreach (self::DECIMALS as $column) {
                $table->string($column, 32)->nullable();
            }

            $table->bigInteger('units_issued')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_issuer_profiles', function (Blueprint $table): void {
            $table->dropColumn([...self::DECIMALS, 'units_issued']);
        });
    }
};
