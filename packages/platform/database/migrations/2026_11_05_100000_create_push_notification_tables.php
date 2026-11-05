<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications sent to a person's devices — the phone's own notifications, through each browser's
 * push service — and which kinds of them each person wants.
 *
 * `push_subscriptions` is one row per device that turned them on. The push service's address and the
 * two keys are what let a server reach that one browser, so all three are encrypted with the
 * account's own key, as the TOTP secret is; `endpoint_hash` (SHA-256 of the address, which is long and
 * random) is what finds a device again when it subscribes twice, since ciphertext cannot be compared.
 * `locale` is the language the device turned them on in: the notification is written in it.
 *
 * `notification_preferences` holds only the kinds a person changed from their default; a kind with no
 * row follows the default its package declared (`NotificationTypeRegistry`).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint_ciphertext');
            $table->text('public_key_ciphertext');
            $table->text('auth_token_ciphertext');

            $table->string('locale', 8);
            $table->string('device_kind', 16);
            $table->string('device_browser', 64)->nullable();
            $table->string('device_platform', 64)->nullable();

            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('type', 64);
            $table->boolean('push_enabled');

            $table->timestampsTz();

            $table->primary(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('push_subscriptions');
    }
};
