<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the same BVB page shows beside a company's meetings and shareholders, kept with them: its market
 * indicators (capitalisation, P/E, P/BV, EPS, dividend yield, the last dividend and its year), its 52-week
 * range, the issue (shares, face value, share capital, the day trading began), its segment and category,
 * and its financial calendar. Each null where the page leaves it out — and for a profile read before this.
 * Decimals as text, as everywhere in this package (`Decimal`).
 */
return new class () extends Migration {
    private const array DECIMALS = [
        'market_cap', 'price_earnings', 'price_book', 'earnings_per_share', 'dividend_yield', 'last_dividend',
        'week52_high', 'week52_low', 'face_value', 'share_capital',
    ];

    public function up(): void
    {
        Schema::table('ext_issuer_profiles', function (Blueprint $table): void {
            foreach (self::DECIMALS as $column) {
                $table->string($column, 32)->nullable();
            }

            $table->smallInteger('last_dividend_year')->nullable();
            $table->bigInteger('shares_total')->nullable();
            $table->date('trading_since')->nullable();
            $table->string('segment', 64)->nullable();
            $table->string('category', 64)->nullable();
            $table->json('financial_calendar')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_issuer_profiles', function (Blueprint $table): void {
            $table->dropColumn([...self::DECIMALS, 'last_dividend_year', 'shares_total', 'trading_since', 'segment', 'category', 'financial_calendar']);
        });
    }
};
