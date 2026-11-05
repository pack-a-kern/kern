<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Notifications\DeviceNoticesTest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Notifications\Models\Device;
use Kern\Notifications\Notifications;
use Kern\Notifications\Testing\BrowserSubscription;
use Kern\Platform\Crypto\Exceptions\EnvelopeException;
use Kern\Platform\Identity\Actions\EraseUser;
use Kern\Platform\Identity\Devices\EncryptedDevice;
use Kern\Platform\Identity\Models\User;

/**
 * What this installation adds to `kern/notifications`: a user's devices sealed under that user's own
 * key, named as the sessions list names them, recorded in the audit trail, and gone when the user is.
 *
 * The package's own suite proves the rest — the push service guard, the switches, the sending.
 */
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

it('seals a device under its user’s key, not the application key', function (): void {
    $user = User::factory()->create();
    $subscription = BrowserSubscription::make();

    $this->actingAs($user)
        ->withHeader('User-Agent', IPHONE)
        ->postJson('/api/v1/notifications/push/devices', $subscription)
        ->assertCreated();

    $row = DB::table('notification_devices')->where('notifiable_id', $user->id)->sole();
    $device = Notifications::devicesOf($user)->sole();

    expect(Notifications::deviceModel())->toBe(EncryptedDevice::class)
        ->and($row->endpoint)->not->toContain('fcm.googleapis.com')
        ->and($row->auth_token)->not->toContain($subscription['keys']['auth'])
        ->and($device->endpoint)->toBe($subscription['endpoint'])
        ->and($device->device_kind)->toBe('mobile')
        ->and($device->device_platform)->toBe('iOS');

    // Bound to its row: the same ciphertext under another device's id no longer opens.
    DB::table('notification_devices')->where('id', $row->id)->update(['id' => (string) Str::uuid7()]);
    expect(fn () => Notifications::devicesOf($user)->sole()->endpoint)->toThrow(EnvelopeException::class);
});

it('records each device turned on or off in the user’s audit trail', function (): void {
    $user = User::factory()->create();

    $id = $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make())->json('id');
    assert(is_string($id));
    $this->actingAs($user)->deleteJson("/api/v1/notifications/push/devices/{$id}")->assertNoContent();

    expect(DB::table('audit_events')->where('actor_id', $user->id)->where('resource_id', $id)->orderBy('occurred_at')->pluck('event_type')->all())
        ->toBe(['PushDeviceAdded', 'PushDeviceRemoved']);
});

it('takes an erased user’s devices and choices with them', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make());
    $this->actingAs($user)->patchJson('/api/v1/notifications/preferences', ['type' => 'deposit-maturity', 'enabled' => false])->assertOk();

    app(EraseUser::class)($user, $user->id);

    expect(Device::query()->where('notifiable_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('notification_preferences')->where('notifiable_id', $user->id)->exists())->toBeFalse();
});
