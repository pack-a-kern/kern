<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Kern\Notifications\Actions\ForgetNotifiable;
use Kern\Notifications\Events\DeviceAdded;
use Kern\Notifications\Events\DeviceRemoved;
use Kern\Notifications\Models\Device;
use Kern\Notifications\Services\Preferences;
use Kern\Notifications\Testing\BrowserSubscription;
use Kern\Notifications\Tests\Fixtures\Person;

/**
 * A person's devices: turned on encrypted, only on a real push service, only their own, and gone
 * with them.
 */
it('turns notices on for a device, keeping its address and keys encrypted', function (): void {
    Event::fake([DeviceAdded::class]);
    $ada = Person::query()->create(['name' => 'Ada']);
    $subscription = BrowserSubscription::make();

    $id = $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', $subscription)->assertCreated()->json('id');

    $row = DB::table('notification_devices')->sole();
    expect($row->notifiable_id)->toBe($ada->id)
        ->and($row->endpoint_hash)->toBe(hash('sha256', $subscription['endpoint']))
        ->and($row->endpoint)->not->toContain('fcm.googleapis.com')
        ->and($row->public_key)->not->toContain($subscription['keys']['p256dh'])
        ->and($row->auth_token)->not->toContain($subscription['keys']['auth'])
        ->and(Device::query()->sole()->endpoint)->toBe($subscription['endpoint']);
    Event::assertDispatched(DeviceAdded::class, static fn (DeviceAdded $event): bool => $event->deviceId === $id && $event->notifiable->is($ada));
});

it('refuses an address that is not a browser push service', function (string $endpoint): void {
    $ada = Person::query()->create(['name' => 'Ada']);

    $this->actingAs($ada)
        ->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make($endpoint))
        ->assertStatus(422)
        ->assertJsonValidationErrors('endpoint');

    expect(Device::query()->exists())->toBeFalse();
})->with([
    'plain http' => ['http://fcm.googleapis.com/fcm/send/x'],
    'an internal address' => ['https://10.0.0.5/push'],
    'this server' => ['https://localhost/api/v1/admin/users'],
    'a look-alike host' => ['https://fcm.googleapis.com.evil.example/x'],
    'another port' => ['https://fcm.googleapis.com:8443/x'],
    'credentials in it' => ['https://user:pass@fcm.googleapis.com/x'],
]);

it('treats the same browser subscribing again as the same device', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $subscription = BrowserSubscription::make();

    $first = $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', $subscription)->json('id');
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', ['locale' => 'ro'] + $subscription)
        ->assertOk()
        ->assertJson(['id' => $first]);

    expect(Device::query()->sole()->locale)->toBe('ro');
});

it('gives a device to whoever signs in on it, never to both', function (): void {
    $subscription = BrowserSubscription::make();
    $this->actingAs(Person::query()->create(['name' => 'Ada']))->postJson('/api/v1/notifications/push/devices', $subscription);

    $grace = Person::query()->create(['name' => 'Grace']);
    $this->actingAs($grace)->postJson('/api/v1/notifications/push/devices', $subscription)->assertCreated();

    expect(Device::query()->sole()->notifiable_id)->toBe($grace->id);
});

it('lists and removes only the person’s own devices, never their address', function (): void {
    Event::fake([DeviceRemoved::class]);
    $ada = Person::query()->create(['name' => 'Ada']);
    $grace = Person::query()->create(['name' => 'Grace']);
    $mine = $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make('https://fcm.googleapis.com/fcm/send/a'))->json('id');
    $theirs = $this->actingAs($grace)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make('https://web.push.apple.com/b'))->json('id');

    $listed = $this->actingAs($ada)->getJson('/api/v1/notifications/push/devices')->assertOk();
    expect($listed->json('devices'))->toHaveCount(1)
        ->and(json_encode($listed->json()))->not->toContain('fcm.googleapis.com');

    $this->actingAs($ada)->deleteJson("/api/v1/notifications/push/devices/{$theirs}")->assertNotFound();
    $this->actingAs($ada)->deleteJson("/api/v1/notifications/push/devices/{$mine}")->assertNoContent();

    expect(Device::query()->pluck('id')->all())->toBe([$theirs]);
    Event::assertDispatchedTimes(DeviceRemoved::class, 1);
});

it('stops at the configured number of devices per person', function (): void {
    config(['notifications.push.max_devices' => 1]);
    $ada = Person::query()->create(['name' => 'Ada']);
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make('https://fcm.googleapis.com/fcm/send/a'))->assertCreated();

    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make('https://fcm.googleapis.com/fcm/send/b'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('endpoint');
});

it('takes a person’s devices and choices with them', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $grace = Person::query()->create(['name' => 'Grace']);
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make('https://fcm.googleapis.com/fcm/send/a'));
    $this->actingAs($grace)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make('https://fcm.googleapis.com/fcm/send/b'));
    app(Preferences::class)->set($ada, 'order-shipped', false);

    app(ForgetNotifiable::class)($ada);

    expect(Device::query()->sole()->notifiable_id)->toBe($grace->id)
        ->and(DB::table('notification_preferences')->exists())->toBeFalse();
});
