<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The devices that receive a person's notices, and which kinds of notice each person wants there.
 *
 * `notification_devices` is one row per browser that turned notifications on. The push service's
 * address and the two keys are what let a server reach that one browser, so the model encrypts all
 * three; `endpoint_hash` (SHA-256 of the address, which is long and random) finds a device again when
 * it subscribes twice, since ciphertext cannot be compared. `locale` is the language the device turned
 * them on in: what arrives there is written in it.
 *
 * `notification_preferences` holds only the kinds a person turned off or back on; a kind with no row
 * is on.
 *
 * No foreign key to the person: `notifiable` is polymorphic, and the application may have more than
 * one kind of person. `ForgetNotifiable` removes both when a person goes.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notification_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('notifiable');

            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');

            $table->string('locale', 8);
            $table->string('device_kind', 16)->nullable();
            $table->string('device_browser', 64)->nullable();
            $table->string('device_platform', 64)->nullable();

            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->morphs('notifiable');
            $table->string('type', 64);
            $table->boolean('push_enabled');
            $table->timestampsTz();

            $table->primary(['notifiable_type', 'notifiable_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_devices');
    }
};
