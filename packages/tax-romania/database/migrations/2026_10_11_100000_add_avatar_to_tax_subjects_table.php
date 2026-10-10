<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture for a taxpayer, the same two columns a user has.
 *
 * Same shape and same reasoning as `add_avatar_to_users_table`: the path is a column rather than
 * something derived from the id, so "has a picture" comes back with the row instead of being a
 * filesystem question asked once per row while a list renders; and `avatar_updated_at` is the cache
 * key, because the file keeps one name per owner and is overwritten in place, so the timestamp in the
 * query string is what makes a new upload a new URL.
 *
 * Not encrypted, unlike almost everything else on this table. The name and the fiscal identifier are
 * ciphertext because they identify a person to anyone who reads the database; a picture somebody chose
 * for a taxpayer card is not that, and treating it as if it were would mean a decrypt on every image
 * request for no gain. The route in front of it is what limits who may see it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('tax_subjects', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('status');
            $table->timestampTz('avatar_updated_at')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('tax_subjects', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'avatar_updated_at']);
        });
    }
};
