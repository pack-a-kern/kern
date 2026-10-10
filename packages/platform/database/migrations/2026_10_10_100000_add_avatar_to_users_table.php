<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A face, instead of two letters.
 *
 * Two columns rather than one. The path could be derived from the id — it very nearly is — but then
 * "does this person have an avatar" becomes a question for the filesystem, asked once per row while a
 * list of users renders. It is a column so the answer comes back with the row.
 *
 * `avatar_updated_at` is the cache key. The stored file keeps one name per user and is overwritten in
 * place, so the URL never changes and a browser would hold the old face indefinitely; the timestamp
 * goes in the query string and makes each new upload a different URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('status');
            $table->timestampTz('avatar_updated_at')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'avatar_updated_at']);
        });
    }
};
