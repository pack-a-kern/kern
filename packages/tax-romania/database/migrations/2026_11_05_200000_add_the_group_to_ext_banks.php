<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The group a bank belongs to — BCR to Erste Group — from GLEIF's ownership records.
 *
 * Not `parent_name` / `parent_lei`: those are the ECB's, and say whose **branch** a row is (ING
 * Bank N.V. for its Bucharest branch). That meaning is load-bearing — a bank's display name is
 * built differently for a branch — and a subsidiary is a bank of its own, so the group is a fact of
 * its own. Filled by `Kern\TaxRomania\Banks\Services\BankGroups` after each import; null where GLEIF
 * records no parent, or where the bank is the top of its own group.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_banks', function (Blueprint $table): void {
            // Built from the group's registered name, as a bank's own `name` is.
            $table->text('group_name')->nullable()->after('parent_lei');
            $table->string('group_lei', 20)->nullable()->after('group_name');
        });
    }

    public function down(): void
    {
        Schema::table('ext_banks', function (Blueprint $table): void {
            $table->dropColumn(['group_name', 'group_lei']);
        });
    }
};
