<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The version of `kern/legislation`'s parser the order's provisions were read under when its issues were
 * read: a law read again under a newer one may say more — a coupon's period, say — so its issues are read
 * again. An order read before this was kept says nothing — null — and is read again once.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_treasury_orders', function (Blueprint $table): void {
            $table->string('parser_version', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_treasury_orders', function (Blueprint $table): void {
            $table->dropColumn('parser_version');
        });
    }
};
