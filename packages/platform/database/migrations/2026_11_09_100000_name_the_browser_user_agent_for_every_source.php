<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `User-Agent` saved for legislatie.just.ro alone becomes the one every source going out as a
 * browser sends (ADR 0025 §3): the stored value moves to the new key, so an installation keeps it. Not a
 * secret, so it is in `value_plain` and moves as it is.
 */
return new class () extends Migration {
    public function up(): void
    {
        $this->rename('legislation.portal_user_agent', 'sources.browser_user_agent');
    }

    public function down(): void
    {
        $this->rename('sources.browser_user_agent', 'legislation.portal_user_agent');
    }

    private function rename(string $from, string $to): void
    {
        DB::table('settings')->where('key', $from)->update(['key' => $to]);
    }
};
