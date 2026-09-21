<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's own notifications table, for the bell.
 *
 * The bell showed a hard-coded "document processed" entry: there was no feed behind it. This is the
 * framework's standard shape — one row per notice, per user, with when it was read — keyed by UUID
 * because users are.
 *
 * `data` is plain text on purpose, and so holds nothing personal: ids, counts and closed codes. What
 * a notice is about — which statement, how many transactions — is shown by fetching the import it
 * points at, through the same tenant-scoped endpoint as everywhere else.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            // Microseconds, not seconds: the bell lists newest first, and two notices about one import
            // are routinely raised within the same second — read, then written — which whole-second
            // timestamps cannot put in order.
            $table->timestampTz('read_at', 6)->nullable();
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
