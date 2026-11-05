<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's own notifications table, which the bell reads: one row per notice, per person, with when
 * it was read.
 *
 * Skipped where it already exists — an application that ran `make:notifications-table` before it
 * installed this package keeps its own. `notifiable` takes the application's morph key type
 * (`Schema::morphUsingUuids()` and friends), so it fits whatever key its people have.
 *
 * `data` is plain text and holds only what `Notice` stores: ids and codes, never what a person would
 * mind being read. The words are written from them when the notice is shown.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            // Microseconds, not seconds: the bell lists newest first, and two notices about one thing
            // are routinely raised within the same second, which whole seconds cannot put in order.
            $table->timestampTz('read_at', 6)->nullable();
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
