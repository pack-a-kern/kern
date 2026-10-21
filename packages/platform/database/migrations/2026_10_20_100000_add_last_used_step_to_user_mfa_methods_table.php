<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time step of the last authenticator code accepted, so that code cannot be accepted again.
 *
 * A code is valid for its own 30 seconds and one period either side, about 90 seconds in all, and
 * `last_used_at` was written but never read — so one code signed in as often as it was sent in that
 * time. A step rather than a timestamp, because the step is what a code is: a later code from the
 * same window is the same code, and an earlier one is older than one already used.
 *
 * Null until the first sign-in with a code; nothing before this column existed is backfilled, because
 * a step can only come from a code.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('user_mfa_methods', function (Blueprint $table): void {
            $table->bigInteger('last_used_step')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_mfa_methods', function (Blueprint $table): void {
            $table->dropColumn('last_used_step');
        });
    }
};
